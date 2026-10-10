<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        body { margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f5f5f5; }
        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background: linear-gradient(135deg, #FF6B35 0%, #FF8C42 100%); padding: 32px 20px; text-align: center; }
        .header .logo { width: 120px; height: auto; margin: 0 auto 16px auto; display: block; }
        .header h1 { color: #ffffff; margin: 0; font-size: 24px; font-weight: bold; }
        .content { padding: 32px 30px; }
        .greeting { font-size: 18px; color: #333333; margin-bottom: 16px; }
        .message { font-size: 15px; color: #666666; line-height: 1.6; margin-bottom: 24px; }
        .details { background-color: #f8f9fa; border-left: 4px solid #FF6B35; border-radius: 6px; padding: 16px 20px; margin: 24px 0; font-size: 14px; color: #333333; }
        .details p { margin: 4px 0; }
        .button-wrap { text-align: center; }
        .button { display: inline-block; padding: 15px 30px; background: linear-gradient(135deg, #FF6B35 0%, #FF8C42 100%); color: #ffffff !important; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 10px 0 20px 0; }
        .footer { background-color: #f8f9fa; padding: 30px; text-align: center; border-top: 1px solid #e9ecef; }
        .footer p { margin: 5px 0; font-size: 13px; color: #999999; }
        .footer .team { font-size: 14px; color: #FF6B35; font-weight: bold; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <img src="{{ asset('images/asso-logo.png') }}" alt="ASSO Logo" class="logo">
            <h1>{{ $title }}</h1>
        </div>

        <div class="content">
            <div class="greeting">{{ __('mail.dispute_staff.greeting', ['name' => $employee->first_name]) }}</div>

            <div class="message">
                <p>{{ __('mail.dispute_staff.intro') }}</p>
                <p><strong>{{ $body }}</strong></p>
            </div>

            <div class="details">
                <p>{{ __('mail.dispute_staff.claim') }} : <strong>{{ $dispute->number }}</strong></p>
                @if($dispute->order)
                    <p>{{ __('mail.dispute_staff.order') }} : <strong>#{{ $dispute->order->order_number }}</strong></p>
                @endif
            </div>

            <div class="button-wrap">
                <a href="{{ $disputeUrl }}" class="button">{{ __('mail.dispute_staff.cta') }}</a>
            </div>
        </div>

        <div class="footer">
            <p>{{ __('mail.dispute_staff.footer') }}</p>
            <p class="team">{{ __('mail.dispute_staff.team') }}</p>
        </div>
    </div>
</body>
</html>
