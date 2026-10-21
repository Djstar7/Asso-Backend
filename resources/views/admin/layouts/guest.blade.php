<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Login') - ASSO Admin</title>

    <!-- Tailwind CSS -->
    <!-- Servi par ASSO : le CDN tailwindcss.com est parfois injoignable (DNS), la page restait sans style. -->
    <script src="{{ asset('vendor/tailwindcss/tailwindcss-3.4.17.js') }}"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#fff7ed',
                            100: '#ffedd5',
                            200: '#fed7aa',
                            300: '#fdba74',
                            400: '#fb923c',
                            500: '#f97316',
                            600: '#ea580c',
                            700: '#c2410c',
                            800: '#9a3412',
                            900: '#7c2d12',
                        }
                    }
                }
            }
        }
    </script>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 25%, #f093fb 50%, #f5576c 75%, #ffd84d 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .shape {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.3;
            animation: float 20s ease-in-out infinite;
        }

        .shape-1 {
            width: 600px;
            height: 600px;
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            top: -200px;
            left: -200px;
            animation-delay: 0s;
        }

        .shape-2 {
            width: 500px;
            height: 500px;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            bottom: -150px;
            right: -150px;
            animation-delay: 5s;
        }

        .shape-3 {
            width: 400px;
            height: 400px;
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            top: 50%;
            right: -100px;
            animation-delay: 10s;
        }

        @keyframes float {
            0%, 100% {
                transform: translate(0, 0) scale(1);
            }
            33% {
                transform: translate(30px, -30px) scale(1.1);
            }
            66% {
                transform: translate(-20px, 20px) scale(0.9);
            }
        }

        .glass-morphism {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        /* Floating particles */
        .particle {
            position: absolute;
            background: rgba(255, 255, 255, 0.5);
            border-radius: 50%;
            pointer-events: none;
            animation: rise 10s infinite ease-in;
        }

        @keyframes rise {
            0% {
                transform: translateY(0) scale(1);
                opacity: 0;
            }
            50% {
                opacity: 0.8;
            }
            100% {
                transform: translateY(-100vh) scale(0);
                opacity: 0;
            }
        }
    </style>

    <link rel="stylesheet" href="{{ asset('css/admin-checkbox.css') }}">

    @stack('styles')
</head>
<body class="min-h-screen flex items-center justify-center p-4 relative overflow-hidden">
    <!-- Animated Background Shapes -->
    <div class="shape shape-1"></div>
    <div class="shape shape-2"></div>
    <div class="shape shape-3"></div>

    <!-- Floating Particles -->
    <script>
        // Create floating particles
        for (let i = 0; i < 20; i++) {
            const particle = document.createElement('div');
            particle.className = 'particle';
            particle.style.width = Math.random() * 10 + 5 + 'px';
            particle.style.height = particle.style.width;
            particle.style.left = Math.random() * 100 + '%';
            particle.style.animationDelay = Math.random() * 10 + 's';
            particle.style.animationDuration = (Math.random() * 10 + 10) + 's';
            document.body.appendChild(particle);
        }
    </script>

    <!-- Main Content -->
    <div class="w-full max-w-md relative z-10">
        @yield('content')
    </div>

    @stack('scripts')
</body>
</html>
