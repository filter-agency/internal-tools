Idea Box: {!! $suggestion['title'] !!}

From: {!! $submitterName !!} <{!! $submitterEmail !!}>

@foreach(['proposal' => 'What should we do?', 'reason' => 'Why should we do it?', 'benefits' => 'How would it benefit us or a client?', 'exploration' => 'What have you explored or tried so far?'] as $field => $label)
{!! $label !!}

{!! $suggestion[$field] !!}

@endforeach
