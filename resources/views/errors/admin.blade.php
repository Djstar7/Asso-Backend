<!doctype html>
<html lang="fr">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Erreur {{ $status }} — ASSO</title></head>
<body style="margin:0;background:#111318;color:#f3f4f6;font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh">
<main style="max-width:34rem;padding:2rem">
    <p style="color:#fb923c">ASSO · {{ $status }}</p>
    <h1>L’action n’a pas pu aboutir</h1>
    <p role="alert">{{ $message }}</p>
    <a href="{{ route('admin.login') }}" style="color:#fb923c">Revenir à l’administration</a>
</main>
</body>
</html>
