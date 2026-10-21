<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;background:#f5f5f5;font-family:Arial,sans-serif;color:#262626">
    <div style="max-width:640px;margin:24px auto;background:#fff;padding:28px;border-top:4px solid #ed7921">
        <img src="{{ asset('logo/Asso.png') }}" alt="ASSO" width="100">
        <h1 style="font-size:22px">{{ __('mail.admin_actions.'.($reminder ? 'reminder_subject' : 'subject')) }}</h1>
        <p>{{ __('mail.admin_actions.greeting', ['name' => $employee->first_name]) }}</p>
        <p style="line-height:1.6">{{ __('mail.admin_actions.intro') }}</p>
        @foreach($groups as $section => $group)
            <h2 style="font-size:17px;margin-top:24px"><a href="{{ $group['url'] }}" style="color:#262626">{{ __('mail.admin_actions.sections.'.$section) }} ({{ $group['count'] }})</a></h2>
            <ul style="padding-left:20px;line-height:1.8">
                @foreach($group['items'] as $item)
                    <li><a href="{{ $item['url'] }}" style="color:#b45309">{{ $item['label'] }}</a></li>
                @endforeach
            </ul>
            @if($group['count'] > count($group['items']))
                <p style="color:#666">{{ __('mail.admin_actions.more', ['count' => $group['count'] - count($group['items'])]) }}</p>
            @endif
        @endforeach
        <p style="font-size:13px;line-height:1.6;color:#666;border-top:1px solid #eee;padding-top:16px">{{ __('mail.admin_actions.access_notice') }}</p>
    </div>
</body>
</html>
