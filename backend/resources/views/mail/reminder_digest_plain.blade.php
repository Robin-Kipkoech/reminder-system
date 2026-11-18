Hello {{ $greetingName }},

Here are your items due today and coming up:
@foreach($groups as $label => $group)
@if(count($group) > 0)

{{ $label }}
@foreach($group as $entry)
{{ $entry['title'] }}
Client: {{ $entry['client'] }}
Due: {{ $entry['due_date'] }} ({{ $entry['days_remaining'] }} days remaining)
{{ $entry['url'] }}
@endforeach
@endif
@endforeach

--
You received this because you have a Reminder System account. You can choose which emails you get in My profile.
