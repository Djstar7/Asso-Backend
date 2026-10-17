<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Code de Synchronisation - ASSO</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f5f5;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
        }
        .header {
            background: linear-gradient(135deg, #FF6B35 0%, #FF8C42 100%);
            padding: 40px 20px;
            text-align: center;
        }
        .header .logo {
            width: 120px;
            height: auto;
            margin: 0 auto 20px auto;
            display: block;
        }
        .header h1 {
            color: #ffffff;
            margin: 0;
            font-size: 32px;
            font-weight: bold;
        }
        .header p {
            color: #ffffff;
            margin: 10px 0 0 0;
            font-size: 16px;
            opacity: 0.95;
        }
        .content {
            padding: 40px 30px;
        }
        .greeting {
            font-size: 18px;
            color: #333333;
            margin-bottom: 20px;
        }
        .message {
            font-size: 15px;
            color: #666666;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .sync-code-container {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 2px solid #FF6B35;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            margin: 30px 0;
        }
        .sync-code-label {
            font-size: 14px;
            color: #666666;
            margin-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .sync-code {
            font-size: 36px;
            font-weight: bold;
            color: #FF6B35;
            font-family: 'Courier New', monospace;
            letter-spacing: 4px;
            margin: 10px 0;
        }
        .expiry-info {
            font-size: 13px;
            color: #999999;
            margin-top: 15px;
        }
        .instructions {
            background-color: #f8f9fa;
            border-left: 4px solid #FF6B35;
            padding: 20px;
            margin: 30px 0;
            border-radius: 4px;
        }
        .instructions h3 {
            margin: 0 0 15px 0;
            color: #333333;
            font-size: 16px;
        }
        .instructions ol {
            margin: 0;
            padding-left: 20px;
        }
        .instructions li {
            margin-bottom: 10px;
            color: #666666;
            line-height: 1.5;
        }
        .company-info {
            background-color: #fff8f5;
            border: 1px solid #ffe4d6;
            border-radius: 8px;
            padding: 20px;
            margin: 30px 0;
        }
        .company-info h4 {
            margin: 0 0 15px 0;
            color: #FF6B35;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .company-detail {
            display: flex;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .company-detail .label {
            font-weight: bold;
            color: #333333;
            min-width: 120px;
        }
        .company-detail .value {
            color: #666666;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #e9ecef;
        }
        .footer p {
            margin: 5px 0;
            font-size: 13px;
            color: #999999;
        }
        .footer .team {
            font-size: 14px;
            color: #FF6B35;
            font-weight: bold;
            margin-top: 15px;
        }
        .warning {
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 6px;
            padding: 15px;
            margin: 20px 0;
            font-size: 13px;
            color: #856404;
        }
        .button {
            display: inline-block;
            padding: 15px 30px;
            background: linear-gradient(135deg, #FF6B35 0%, #FF8C42 100%);
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
            margin: 20px 0;
            transition: transform 0.2s;
        }
        .button:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="header">
            <img src="{{ asset('images/asso-logo.png') }}" alt="ASSO Logo" class="logo">
            <h1>🎉 Bienvenue sur ASSO</h1>
            <p>Votre plateforme de livraison intelligente</p>
        </div>

        <!-- Content -->
        <div class="content">
            <div class="greeting">
                Bonjour <strong>{{ $company->name }}</strong>,
            </div>

            <div class="message">
                <p>Nous sommes ravis de vous accueillir en tant que partenaire de livraison sur la plateforme <strong>ASSO</strong> !</p>
                <p>Votre entreprise a été enregistrée avec succès et nous sommes impatients de collaborer avec vous pour offrir un service de livraison exceptionnel à nos clients.</p>
            </div>

            <!-- Sync Code -->
            <div class="sync-code-container">
                <div class="sync-code-label">🔐 Votre Code de Synchronisation</div>
                <div class="sync-code">{{ $syncCode }}</div>
                <div class="expiry-info">
                    ⏰ Valide jusqu'au {{ \Carbon\Carbon::parse($expiresAt)->displayTz()->format('d/m/Y à H:i') }}
                </div>
            </div>

            <!-- Instructions -->
            <div class="instructions">
                <h3>📋 Comment synchroniser votre compte ?</h3>
                <ol>
                    <li><strong>Téléchargez l'application mobile ASSO</strong> depuis votre app store</li>
                    <li><strong>Créez votre compte personnel</strong> ou connectez-vous si vous en avez déjà un</li>
                    <li><strong>Accédez à la section "Mode Livreur"</strong> dans le menu latéral gauche</li>
                    <li><strong>Saisissez le code ci-dessus</strong> pour lier votre compte à votre entreprise {{ $company->name }}</li>
                    <li><strong>Commencez à gérer vos livraisons</strong> immédiatement !</li>
                </ol>
            </div>

            <!-- Company Info -->
            <div class="company-info">
                <h4>📦 Informations de votre entreprise</h4>
                <div class="company-detail">
                    <span class="label">Nom de l'entreprise :</span>
                    <span class="value">{{ $company->name }}</span>
                </div>
                <div class="company-detail">
                    <span class="label">Email :</span>
                    <span class="value">{{ $company->email }}</span>
                </div>
                <div class="company-detail">
                    <span class="label">Téléphone :</span>
                    <span class="value">{{ $company->phone }}</span>
                </div>
                @if($company->deliveryZones->count() > 0)
                <div class="company-detail">
                    <span class="label">Zones de livraison :</span>
                    <span class="value">{{ $company->deliveryZones->pluck('name')->join(', ') }}</span>
                </div>
                @endif
            </div>

            <!-- Warning -->
            <div class="warning">
                <strong>⚠️ Important :</strong> Ce code est personnel et unique. Ne le partagez avec personne. Il expire dans 30 jours.
            </div>

            <div class="message">
                <p>Si vous avez des questions ou besoin d'assistance, n'hésitez pas à nous contacter. Notre équipe est là pour vous accompagner dans votre intégration.</p>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p class="team">L'équipe ASSO</p>
            <p>Plateforme de livraison intelligente</p>
            <p>📧 {{ config('mail.from.address') }} | 📞 Support technique disponible 24/7</p>
            <p style="margin-top: 20px; color: #cccccc; font-size: 12px;">
                Cet email a été envoyé automatiquement, merci de ne pas y répondre.<br>
                © {{ date('Y') }} ASSO. Tous droits réservés.
            </p>
        </div>
    </div>
</body>
</html>
