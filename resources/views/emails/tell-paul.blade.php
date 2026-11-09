<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Idea Box</title></head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family:Arial,Helvetica,sans-serif; color:#1f2937;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f3f4f6;">
        <tr><td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px; background-color:#ffffff; border:1px solid #e5e7eb; border-radius:8px;">
                <tr><td style="padding:24px 32px; background-color:#002f5f; color:#ffffff;">
                    <p style="margin:0 0 8px; font-size:12px; letter-spacing:1px; text-transform:uppercase;">Filter Internal Tools · Idea Box</p>
                    <h1 style="margin:0; font-size:24px; line-height:1.4; overflow-wrap:anywhere;">{{ $suggestion['title'] }}</h1>
                </td></tr>
                <tr><td style="padding:20px 32px; border-bottom:1px solid #e5e7eb;">
                    <p style="margin:0 0 4px; font-size:14px; line-height:1.6;"><strong>Submitted by {{ $submitterName }}</strong><br><a href="mailto:{{ $submitterEmail }}" style="color:#002f5f;">{{ $submitterEmail }}</a></p>
                    <p style="margin:8px 0 0; font-size:13px; color:#6b7280;">Reply to this email to follow up with the sender.</p>
                </td></tr>
                @foreach(['proposal' => 'What should we do?', 'reason' => 'Why should we do it?', 'benefits' => 'How would it benefit us or a client?', 'exploration' => 'What have you explored or tried so far?'] as $field => $label)
                    <tr><td style="padding:24px 32px;{{ !$loop->last ? ' border-bottom:1px solid #e5e7eb;' : '' }}">
                        <h2 style="margin:0 0 12px; font-size:16px; line-height:1.5; color:#002f5f;">{{ $label }}</h2>
                        <p style="margin:0; font-size:15px; line-height:1.7; overflow-wrap:anywhere; word-break:break-word;">{!! nl2br(e($suggestion[$field])) !!}</p>
                    </td></tr>
                @endforeach
            </table>
        </td></tr>
    </table>
</body>
</html>
