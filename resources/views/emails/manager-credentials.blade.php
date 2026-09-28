<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accès au tableau de bord - ASSO</title>
    <style>
        body { margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f5f5f5; }
        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background: linear-gradient(135deg, #FF6B35 0%, #FF8C42 100%); padding: 40px 20px; text-align: center; }
        .header .logo { width: 120px; height: auto; margin: 0 auto 20px auto; display: block; }
        .header h1 { color: #ffffff; margin: 0; font-size: 28px; font-weight: bold; }
        .header p { color: #ffffff; margin: 10px 0 0 0; font-size: 16px; opacity: 0.95; }
        .content { padding: 40px 30px; }
        .greeting { font-size: 18px; color: #333333; margin-bottom: 20px; }
        .message { font-size: 15px; color: #666666; line-height: 1.6; margin-bottom: 30px; }
        .credentials { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border: 2px solid #FF6B35; border-radius: 12px; padding: 25px 30px; margin: 30px 0; }
        .credentials .row { margin-bottom: 15px; }
        .credentials .row:last-child { margin-bottom: 0; }
        .credentials .label { font-size: 13px; color: #666666; text-transform: uppercase; letter-spacing: 1px; }
        .credentials .value { font-size: 18px; font-weight: bold; color: #333333; margin-top: 4px; word-break: break-all; }
        .credentials .password { font-family: 'Courier New', monospace; color: #FF6B35; font-size: 22px; letter-spacing: 2px; }
        .button-wrap { text-align: center; }
        .button { display: inline-block; padding: 15px 30px; background: linear-gradient(135deg, #FF6B35 0%, #FF8C42 100%); color: #ffffff !important; text-decoration: none; border-radius: 8px; font-weight: bold; margin: 10px 0 20px 0; }
        .warning { background-color: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 15px; margin: 20px 0; font-size: 13px; color: #856404; }
        .footer { background-color: #f8f9fa; padding: 30px; text-align: center; border-top: 1px solid #e9ecef; }
        .footer p { margin: 5px 0; font-size: 13px; color: #999999; }
        .footer .team { font-size: 14px; color: #FF6B35; font-weight: bold; margin-top: 15px; }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="header">
            <img src="{{ asset('images/asso-logo.png') }}" alt="ASSO Logo" class="logo">
            <h1>{{ $isReset ? 'Nouveaux accès' : 'Bienvenue dans l\'équipe ASSO' }}</h1>
            <p>Tableau de bord ASSO — {{ $roleLabel }}</p>
        </div>

        <div class="content">
            <div class="greeting">
                Bonjour <strong>{{ $manager->first_name }}</strong>,
            </div>

            <div class="message">
                @if($isReset)
                    <p>Un administrateur a régénéré votre mot de passe d'accès au tableau de bord ASSO. L'ancien mot de passe ne fonctionne plus.</p>
                @else
                    <p>Un administrateur vous a ouvert un accès au tableau de bord ASSO en tant que <strong>{{ $roleLabel }}</strong>.</p>
                @endif
                <p>Voici vos identifiants de connexion :</p>
            </div>

            <div class="credentials">
                <div class="row">
                    <div class="label">Adresse e-mail</div>
                    <div class="value">{{ $manager->email }}</div>
                </div>
                <div class="row">
                    <div class="label">Mot de passe</div>
                    <div class="value password">{{ $plainPassword }}</div>
                </div>
            </div>

            <div class="button-wrap">
                <a href="{{ $loginUrl }}" class="button">Se connecter au tableau de bord</a>
            </div>

            <div class="warning">
                🔒 Ces identifiants sont personnels : ne les partagez avec personne. Si vous n'êtes pas à l'origine de cette demande, prévenez un administrateur ASSO.
            </div>
        </div>

        <div class="footer">
            <p>Cet e-mail a été envoyé automatiquement, merci de ne pas y répondre.</p>
            <p class="team">L'équipe ASSO</p>
        </div>
    </div>
</body>
</html>
