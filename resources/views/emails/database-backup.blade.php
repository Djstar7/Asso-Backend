@php
    $ok = $report['status'] === 'success';
    $drive = $report['drive'];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $ok ? 'Sauvegarde ASSO' : 'Échec de la sauvegarde ASSO' }}</title>
    <style>
        body { margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f5f5f5; }
        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; }
        .header { background: {{ $ok ? 'linear-gradient(135deg, #FF6B35 0%, #FF8C42 100%)' : '#C62828' }}; padding: 28px 20px; text-align: center; }
        .header .logo { width: 110px; height: auto; margin: 0 auto 14px auto; display: block; }
        .header h1 { color: #ffffff; margin: 0; font-size: 22px; font-weight: bold; }
        .content { padding: 28px 30px; font-size: 15px; color: #444444; line-height: 1.6; }
        .details { background-color: #f8f9fa; border-left: 4px solid {{ $ok ? '#FF6B35' : '#C62828' }}; border-radius: 6px; padding: 14px 18px; margin: 20px 0; font-size: 14px; color: #333333; }
        .details td { padding: 3px 12px 3px 0; vertical-align: top; }
        .details td:first-child { color: #777777; white-space: nowrap; }
        .mono { font-family: Consolas, Menlo, monospace; font-size: 12px; word-break: break-all; }
        .ok { color: #2E7D32; font-weight: bold; }
        .ko { color: #C62828; font-weight: bold; }
        .note { font-size: 13px; color: #777777; }
        .footer { background-color: #f8f9fa; padding: 20px 30px; text-align: center; border-top: 1px solid #e9ecef; font-size: 12px; color: #999999; }
    </style>
</head>
<body>
<div class="email-container">
    <div class="header">
        <img src="{{ asset('images/asso-logo.png') }}" alt="ASSO" class="logo">
        <h1>{{ $ok ? 'Sauvegarde quotidienne de la base' : '⚠ Échec de la sauvegarde de la base' }}</h1>
    </div>

    <div class="content">
        @if($ok)
            <p>La sauvegarde de la base <strong>{{ $report['database'] }}</strong> a été réalisée et contrôlée.
                @if($report['attached'])
                    Elle est jointe à ce message (archive ZIP chiffrée).
                @else
                    Elle dépasse la taille autorisée pour une pièce jointe : utilisez la copie Google Drive ci-dessous.
                @endif
            </p>
        @else
            <p><strong>La sauvegarde de cette nuit n'a pas pu être réalisée.</strong> Aucune archive n'a été produite :
                il faut intervenir sur le serveur.</p>
        @endif

        <table class="details">
            <tr><td>Date</td><td>{{ $report['date'] }}</td></tr>
            <tr><td>Base</td><td>{{ $report['database'] }} ({{ $report['host'] }})</td></tr>
            @if($ok)
                <tr><td>Fichier</td><td>{{ $report['file'] }}</td></tr>
                <tr><td>Taille</td><td>{{ $report['size'] }}</td></tr>
                <tr><td>Tables</td><td>{{ $report['tables'] }} <span class="ok">✓ contrôlées</span></td></tr>
                <tr><td>Google Drive</td><td>
                    @switch($drive['status'])
                        @case('ok')
                            <span class="ok">✓ copiée</span> — <a href="{{ $drive['link'] }}">ouvrir sur Drive</a>
                            @break
                        @case('failed')
                            <span class="ko">✗ échec</span><br><span class="note">{{ $drive['error'] }}</span>
                            @break
                        @case('not_configured')
                            <span class="ko">non configuré</span>
                            @break
                        @default
                            désactivé pour cet envoi
                    @endswitch
                </td></tr>
                <tr><td>SHA-256</td><td class="mono">{{ $report['sha256'] }}</td></tr>
            @else
                <tr><td>Erreur</td><td class="ko">{{ $report['error'] }}</td></tr>
            @endif
            <tr><td>Durée</td><td>{{ $report['duration'] }}</td></tr>
        </table>

        @if($ok)
            <p class="note">
                L'archive est chiffrée en AES-256 : ouvrez-la avec 7-Zip (Windows) ou Keka (Mac) et le mot de passe
                des sauvegardes. Le mot de passe n'est jamais envoyé par e-mail.
                Le fichier LISEZMOI.txt de l'archive donne les commandes de restauration.
            </p>
        @endif
    </div>

    <div class="footer">
        Message automatique envoyé chaque nuit à {{ config('backup.time') }} ({{ config('backup.timezone') }}) — ne pas répondre.
    </div>
</div>
</body>
</html>
