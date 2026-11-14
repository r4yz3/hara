Hello {{ $inviteeName }},

You have been invited to join the admin team as {{ $roleLabel }} ({{ $scopeLabel }}).

Open this link to accept. It works once and expires in {{ $hoursValid }} hours:

{!! $url !!}

If you already have an account with this email address, you will be asked for its current password. Otherwise you will choose a new one. You will then set up two-step sign-in before you can use the console.

If you were not expecting this, you can ignore this email. Nothing happens until the link is opened.
