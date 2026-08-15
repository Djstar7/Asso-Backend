<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Code de Vérification - ASSO</title>
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
            color: #666666;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .otp-container {
            background: linear-gradient(135deg, #FF6B35 0%, #FF8C42 100%);
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            margin: 30px 0;
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.2);
        }
        .otp-label {
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 15px;
        }
        .otp-code {
            background-color: #ffffff;
            color: #FF6B35;
            font-size: 48px;
            font-weight: bold;
            letter-spacing: 8px;
            padding: 20px 30px;
            border-radius: 8px;
            display: inline-block;
            font-family: 'Courier New', monospace;
        }
        .expiry-notice {
            background-color: #FFF3E0;
            border-left: 4px solid #FFB74D;
            padding: 15px 20px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .expiry-notice p {
            margin: 0;
            color: #E65100;
            font-size: 14px;
        }
        .security-notice {
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .security-notice h3 {
            color: #FF6B35;
            font-size: 16px;
            margin: 0 0 10px 0;
        }
        .security-notice p {
            color: #666666;
            font-size: 14px;
            line-height: 1.6;
            margin: 0;
        }
        .footer {
            background-color: #f9f9f9;
            padding: 30px;
            text-align: center;
            border-top: 1px solid #eeeeee;
        }
        .footer p {
            color: #999999;
            font-size: 14px;
            margin: 5px 0;
        }
        .footer a {
            color: #FF6B35;
            text-decoration: none;
        }
        @media only screen and (max-width: 600px) {
            .content {
                padding: 30px 20px;
            }
            .otp-code {
                font-size: 36px;
                letter-spacing: 4px;
                padding: 15px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <!-- Header -->
        <div class="header">
            <h1>ASSO</h1>
            <p>Marketplace - Ton marché dans ta poche</p>
        </div>

        <!-- Content -->
        <div class="content">
            <div class="greeting">
                Bonjour,
            </div>

            <div class="message">
                <p>Vous avez demandé un code de vérification pour accéder à votre compte ASSO. Utilisez le code ci-dessous pour continuer :</p>
            </div>

            <!-- OTP Code -->
            <div class="otp-container">
                <div class="otp-label">Votre code de vérification</div>
                <div class="otp-code">{{ $otpCode }}</div>
            </div>

            <!-- Expiry Notice -->
            <div class="expiry-notice">
                <p><strong>⏱️ Important :</strong> Ce code expirera dans <strong>5 minutes</strong>.</p>
            </div>

            <!-- Security Notice -->
            <div class="security-notice">
                <h3>🔒 Sécurité</h3>
                <p>
                    Si vous n'avez pas demandé ce code, veuillez ignorer cet email.
                    Ne partagez jamais ce code avec qui que ce soit. L'équipe ASSO ne vous demandera jamais votre code de vérification.
                </p>
            </div>

            <div class="message" style="margin-top: 30px;">
                <p>Merci d'utiliser ASSO !</p>
                <p style="margin-top: 20px;">Cordialement,<br><strong>L'équipe ASSO</strong></p>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <p>&copy; {{ date('Y') }} ASSO. Tous droits réservés.</p>
            <p>Marketplace - Ton marché dans ta poche</p>
        </div>
    </div>
</body>
</html>
