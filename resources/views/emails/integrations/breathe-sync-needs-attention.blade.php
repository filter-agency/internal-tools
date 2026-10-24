<x-email-shell
  heading="{{ $failure !== null ? 'Internal Tools Breathe HR sync failed' : 'Breathe HR sync needs a check' }}"
  subheading="{{ $failure !== null ? 'No start or leave dates were updated' : 'Some Breathe records could not be matched or applied' }}"
  :cta-url="$usersUrl"
  cta-label="Review users"
>
  @if ($failure !== null)
    <p style="margin:0 0 16px;">
      <strong>Reason:</strong> {{ $failure }}
    </p>
    <p style="margin:0; color:#666;">
      The sync will try again tomorrow. Check the Breathe API key and BREATHE_BASE_URL if this keeps happening.
    </p>
  @endif

  @if ($unmatched !== [])
    <p style="margin:0 0 8px;"><strong>Current Breathe employees with no Internal Tools account</strong></p>
    <ul style="margin:0 0 16px; padding-left:20px;">
      @foreach ($unmatched as $line)
        <li>{{ $line }}</li>
      @endforeach
    </ul>
  @endif

  @if ($problems !== [])
    <p style="margin:0 0 8px;"><strong>Records that were skipped or need fixing</strong></p>
    <ul style="margin:0 0 16px; padding-left:20px;">
      @foreach ($problems as $line)
        <li>{{ $line }}</li>
      @endforeach
    </ul>
  @endif

  @if ($failure === null)
    <p style="margin:0; color:#666;">
      People are matched by work email. You won't get this email again until the list changes.
    </p>
  @endif
</x-email-shell>
