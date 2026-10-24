<?php

namespace App\Mcp\Tools;

use App\Domain\Mcp\InternalMcpActions;
use App\Mcp\Tools\Concerns\InteractsWithInternalTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Return time report totals and optional grouped rows for a date range. Includes time on archived projects.')]
class TimeReport extends Tool
{
    use InteractsWithInternalTools;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()
                ->format('date')
                ->description('Report start date in YYYY-MM-DD format.')
                ->required(),
            'to' => $schema->string()
                ->format('date')
                ->description('Report end date in YYYY-MM-DD format.')
                ->required(),
            'user_id' => $schema->integer()
                ->description('Optional user ID filter. Managers and admins can report on other users.')
                ->nullable(),
            'client_id' => $schema->integer()
                ->description('Optional client ID filter.')
                ->nullable(),
            'project_id' => $schema->integer()
                ->description('Optional project ID filter.')
                ->nullable(),
            'task_id' => $schema->integer()
                ->description('Optional task ID filter.')
                ->nullable(),
            'billable_only' => $schema->boolean()
                ->description('Whether to include only billable time entries. Defaults to false.'),
            'group_by' => $schema->string()
                ->description('Optional grouping dimension for report rows.')
                ->enum(['client', 'project', 'task', 'user'])
                ->nullable(),
        ];
    }

    public function handle(Request $request, InternalMcpActions $actions): ResponseFactory
    {
        return Response::structured($actions->timeReport($this->user($request), $request->all()));
    }
}
