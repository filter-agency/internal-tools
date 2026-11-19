#!/usr/bin/env bash
# Release steps for Internal Tools on ECS, called by .github/workflows/deploy-aws.yml.
#
# Usage: deploy/aws/release.sh <command> <env> [args]
#   image-uri <env> <tag>             print the image URI for a tag
#   image-exists <env> <tag>          exit 0 if the tag is already in ECR
#   deployed <env>                    exit 0 if the services stack exists (not the first deploy)
#   prepare <env> <image-uri>         move the one-off task to the new image (services unchanged)
#   run <env> <timeout-s> <cmd...>    run a one-off task and fail on a non-zero exit
#   down <env>                        maintenance mode on (shared by every container)
#   await-idle <env>                  wait for running jobs to finish (skipped when no workers)
#   up <env>                          maintenance mode off
#   snapshot <env> <suffix>           take a pre-deploy RDS snapshot and wait for it
#   release <env> <image-uri>         move the web, worker and scheduler services to the new image
#   prune-snapshots <env> <keep>      delete older pre-deploy snapshots
set -euo pipefail
cd "$(dirname "$0")/../.."
export AWS_PAGER=""

PROJECT_NAME="internal-tools"
REGION="${AWS_REGION:-eu-west-2}"

USAGE="Usage: deploy/aws/release.sh <command> <env> [args]"
CMD="${1:?$USAGE}"
ENV_NAME="${2:?$USAGE}"
shift 2
[[ "$ENV_NAME" =~ ^[a-z0-9-]+$ ]] || { echo "env must match ^[a-z0-9-]+$" >&2; exit 1; }

ENV_FILE="deploy/aws/${ENV_NAME}.env"
[[ -f "$ENV_FILE" ]] || { echo "Missing $ENV_FILE" >&2; exit 1; }
source "$ENV_FILE"

INFRA_STACK="${PROJECT_NAME}-${ENV_NAME}"
SERVICES_STACK="${PROJECT_NAME}-${ENV_NAME}-services"
SNAPSHOT_PREFIX="${PROJECT_NAME}-${ENV_NAME}-predeploy-"

# Infra outputs passed to services.yml as parameters of the same name
INFRA_PARAMS=(HostName ClusterName CapacityProviderName WebTargetGroupArn TaskExecutionRoleArn TaskRoleArn
  AppSecretArn DbSecretArn WebLogGroupName WorkerLogGroupName SchedulerLogGroupName OneOffLogGroupName)

INFRA_OUTPUTS=""
infra_output() {
  if [[ -z "$INFRA_OUTPUTS" ]]; then
    INFRA_OUTPUTS="$(aws cloudformation describe-stacks --stack-name "$INFRA_STACK" --region "$REGION" \
      --query "Stacks[0].Outputs[].[OutputKey,OutputValue]" --output text)"
  fi
  local value
  value="$(awk -F'\t' -v k="$1" '$1 == k { print $2 }' <<< "$INFRA_OUTPUTS")"
  [[ -n "$value" ]] || { echo "Output $1 missing from $INFRA_STACK" >&2; exit 1; }
  echo "$value"
}

services_output() {
  aws cloudformation describe-stacks --stack-name "$SERVICES_STACK" --region "$REGION" \
    --query "Stacks[0].Outputs[?OutputKey=='$1'].OutputValue" --output text
}

current_image() {
  aws cloudformation describe-stacks --stack-name "$SERVICES_STACK" --region "$REGION" \
    --query "Stacks[0].Parameters[?ParameterKey=='ImageUri'].ParameterValue" --output text 2>/dev/null || true
}

deploy_services() {
  local image="$1" oneoff_image="$2" p v
  local overrides=("ProjectName=${PROJECT_NAME}" "EnvName=${ENV_NAME}" "ImageUri=${image}" "OneOffImageUri=${oneoff_image}")
  for p in "${INFRA_PARAMS[@]}"; do overrides+=("${p}=$(infra_output "$p")"); done
  for v in $(compgen -v CFN_PARAM_ || true); do overrides+=("${v#CFN_PARAM_}=${!v}"); done
  aws cloudformation deploy \
    --template-file deploy/aws/services.yml --stack-name "$SERVICES_STACK" --region "$REGION" \
    --no-fail-on-empty-changeset --parameter-overrides "${overrides[@]}"
}

run_oneoff() {
  local timeout="$1"; shift
  local cluster cp td overrides task_arn task_id status exit_code reason waited=0
  cluster="$(infra_output ClusterName)"
  cp="$(infra_output CapacityProviderName)"
  td="$(services_output OneOffTaskDefinitionArn)"
  overrides="$(jq -cn --args '{containerOverrides: [{name: "app", command: $ARGS.positional}]}' "$@")"

  echo "==> One-off task: $*"
  task_arn="$(aws ecs run-task --cluster "$cluster" --task-definition "$td" --region "$REGION" \
    --capacity-provider-strategy "capacityProvider=${cp},weight=1" \
    --started-by "release-${GITHUB_RUN_ID:-manual}" --overrides "$overrides" \
    --query "tasks[0].taskArn" --output text)"
  [[ -n "$task_arn" && "$task_arn" != "None" ]] || { echo "Task did not start" >&2; exit 1; }
  task_id="${task_arn##*/}"

  while :; do
    status="$(aws ecs describe-tasks --cluster "$cluster" --tasks "$task_arn" --region "$REGION" \
      --query "tasks[0].lastStatus" --output text)"
    [[ "$status" == "STOPPED" ]] && break
    if (( waited >= timeout )); then
      aws ecs stop-task --cluster "$cluster" --task "$task_arn" --region "$REGION" --reason "Timed out" >/dev/null
      echo "One-off task timed out after ${timeout}s" >&2
      exit 1
    fi
    sleep 10
    waited=$((waited + 10))
  done

  aws logs get-log-events --log-group-name "$(infra_output OneOffLogGroupName)" \
    --log-stream-name "oneoff/app/${task_id}" --start-from-head --region "$REGION" \
    --query "events[].message" --output text 2>/dev/null | tr '\t' '\n' || true

  read -r exit_code reason < <(aws ecs describe-tasks --cluster "$cluster" --tasks "$task_arn" --region "$REGION" \
    --query "[tasks[0].containers[0].exitCode, tasks[0].stoppedReason]" --output text)
  [[ "$exit_code" == "0" ]] || { echo "One-off task failed: exit ${exit_code}, ${reason}" >&2; exit 1; }
  echo "==> One-off task finished"
}

case "$CMD" in
  image-uri)
    echo "$(infra_output RepositoryUri):${1:?tag}"
    ;;
  image-exists)
    repo="$(infra_output RepositoryUri)"
    aws ecr describe-images --repository-name "${repo#*/}" --image-ids "imageTag=${1:?tag}" --region "$REGION" >/dev/null 2>&1
    ;;
  deployed)
    aws cloudformation describe-stacks --stack-name "$SERVICES_STACK" --region "$REGION" >/dev/null 2>&1
    ;;
  prepare)
    image="${1:?image-uri}"
    current="$(current_image)"
    [[ -n "$current" && "$current" != "None" ]] || current="$image"
    echo "==> One-off task -> ${image} (services stay on ${current})"
    deploy_services "$current" "$image"
    ;;
  run)
    timeout="${1:?timeout-s}"; shift
    run_oneoff "$timeout" "$@"
    ;;
  down)
    run_oneoff 300 php artisan down --render=errors::503 --retry=60
    ;;
  up)
    run_oneoff 300 php artisan up
    ;;
  await-idle)
    if [[ "${CFN_PARAM_WorkerCount:-0}" == "0" ]]; then
      echo "==> No workers in ${ENV_NAME}; skipping queue:await-idle"
    else
      run_oneoff 900 php artisan queue:await-idle
    fi
    ;;
  snapshot)
    id="${SNAPSHOT_PREFIX}${1:?suffix}"
    db="$(infra_output DbInstanceId)"
    echo "==> Snapshot ${id}"
    aws rds create-db-snapshot --db-instance-identifier "$db" --db-snapshot-identifier "$id" \
      --tags Key=Purpose,Value=predeploy --region "$REGION" >/dev/null
    aws rds wait db-snapshot-available --db-snapshot-identifier "$id" --region "$REGION"
    echo "==> Snapshot available"
    ;;
  release)
    image="${1:?image-uri}"
    echo "==> Services -> ${image}"
    deploy_services "$image" "$image"
    ;;
  prune-snapshots)
    keep="${1:?keep}"
    db="$(infra_output DbInstanceId)"
    snaps=()
    read -r -a snaps < <(aws rds describe-db-snapshots --db-instance-identifier "$db" --snapshot-type manual \
      --region "$REGION" --output text \
      --query "sort_by(DBSnapshots[?starts_with(DBSnapshotIdentifier, '${SNAPSHOT_PREFIX}')], &SnapshotCreateTime)[].DBSnapshotIdentifier") || true
    count="${#snaps[@]}"
    for (( i = 0; i < count - keep; i++ )); do
      echo "==> Deleting ${snaps[$i]}"
      aws rds delete-db-snapshot --db-snapshot-identifier "${snaps[$i]}" --region "$REGION" >/dev/null
    done
    ;;
  *)
    echo "$USAGE" >&2
    exit 1
    ;;
esac
