import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = readFileSync(new URL('../../freshdesk-app/app/scripts/app.js', import.meta.url), 'utf8');

function widget(project) {
    const elements = new Map();
    const element = () => ({
        value: '', children: [],
        classList: { hidden: false, add() { this.hidden = true; }, remove() { this.hidden = false; } },
        appendChild(child) { this.children.push(child); },
    });
    const context = vm.createContext({
        app: { initialized: () => new Promise(() => {}) },
        document: {
            getElementById(id) {
                if (!elements.has(id)) elements.set(id, element());
                return elements.get(id);
            },
            createElement: element,
        },
        console,
    });
    vm.runInContext(script, context);
    context.project = project;
    vm.runInContext('state.projects = [project]; onProjectChange({ target: { value: "1" } }); state.selectedTaskId = 2;', context);
    return { context, elements };
}

const linkedProject = {
    id: 1, tasks: [{ id: 2, name: 'Support', is_billable: true }],
    asana_project_gids: ['board-a', 'board-b'], asana_task_required: true,
    asana_tasks: [{ gid: 'task-a', name: 'First task' }, { gid: 'task-b', name: 'Second task' }],
};

test('Freshdesk shows tasks from the current API response and submits the chosen task', () => {
    const { context, elements } = widget(linkedProject);
    assert.equal(elements.get('asana-row').classList.hidden, false);
    assert.equal(elements.get('asana-select').children.length, 2);
    assert.match(vm.runInContext('validateForSubmission()', context), /pick an Asana task/);
    vm.runInContext('state.selectedAsanaTaskGid = "task-b";', context);
    assert.equal(vm.runInContext('validateForSubmission()', context), null);
    assert.equal(vm.runInContext('buildSubmissionBody(false).asana_task_gid', context), 'task-b');
});

test('Freshdesk allows time without an Asana task when that project makes it optional', () => {
    const { context, elements } = widget({ ...linkedProject, asana_task_required: false });
    assert.equal(elements.get('asana-row').classList.hidden, false);
    assert.equal(vm.runInContext('validateForSubmission()', context), null);
});

test('Freshdesk hides the picker for projects without linked boards', () => {
    const { context, elements } = widget({ ...linkedProject, asana_project_gids: [], asana_tasks: [] });
    assert.equal(elements.get('asana-row').classList.hidden, true);
    assert.equal(vm.runInContext('validateForSubmission()', context), null);
});
