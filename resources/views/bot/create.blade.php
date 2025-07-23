<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🤖 Octomind Bot erstellen</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-gray-800 mb-2">
                🤖 <span class="text-blue-600">Octomind</span> Bot erstellen
            </h1>
            <p class="text-gray-600">Erstelle deinen persönlichen Jira-Ticket-Bot in wenigen Schritten</p>
        </div>

        <!-- Haupt-Formular -->
        <div class="max-w-4xl mx-auto">
            <div class="bg-white rounded-xl shadow-lg p-8">
                @if ($errors->any())
                    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
                        <div class="flex items-center mb-2">
                            <i class="fas fa-exclamation-triangle text-red-500 mr-2"></i>
                            <h3 class="text-red-800 font-semibold">Fehler beim Erstellen des Bots</h3>
                        </div>
                        <ul class="text-red-700 list-disc list-inside">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('bot.store') }}" method="POST" class="space-y-8">
                    @csrf

                    <!-- Bot-Grundeinstellungen -->
                    <div class="border-b border-gray-200 pb-6">
                        <h2 class="text-2xl font-semibold text-gray-800 mb-4 flex items-center">
                            <i class="fas fa-robot text-blue-500 mr-3"></i>
                            Bot-Grundeinstellungen
                        </h2>
                        
                        <div class="grid md:grid-cols-1 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-tag text-gray-400 mr-2"></i>
                                    Bot-Name
                                </label>
                                <input type="text" name="bot_name" value="{{ old('bot_name') }}" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                       placeholder="Mein Jira Bot" required>
                                <p class="text-sm text-gray-500 mt-1">Ein eindeutiger Name für deinen Bot</p>
                            </div>
                        </div>
                    </div>

                    <!-- Jira-Konfiguration -->
                    <div class="border-b border-gray-200 pb-6">
                        <h2 class="text-2xl font-semibold text-gray-800 mb-4 flex items-center">
                            <i class="fab fa-jira text-blue-600 mr-3"></i>
                            Jira-Konfiguration
                        </h2>
                        
                        <div class="grid md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-link text-gray-400 mr-2"></i>
                                    Jira Base URL
                                </label>
                                <input type="url" name="jira_base_url" value="{{ old('jira_base_url') }}" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                       placeholder="https://deine-domain.atlassian.net" required>
                                <p class="text-sm text-gray-500 mt-1">Deine Jira-Instanz URL</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-key text-gray-400 mr-2"></i>
                                    Projekt-Schlüssel
                                </label>
                                <input type="text" name="jira_project_key" value="{{ old('jira_project_key') }}" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                       placeholder="PROJ" required maxlength="10">
                                <p class="text-sm text-gray-500 mt-1">Der Schlüssel deines Jira-Projekts</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-user text-gray-400 mr-2"></i>
                                    Jira Benutzername (E-Mail)
                                </label>
                                <input type="email" name="jira_username" value="{{ old('jira_username') }}" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                       placeholder="deine-email@example.com" required>
                                <p class="text-sm text-gray-500 mt-1">Deine Jira-Anmelde-E-Mail</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-shield-alt text-gray-400 mr-2"></i>
                                    Jira API Token
                                </label>
                                <input type="password" name="jira_api_token" value="{{ old('jira_api_token') }}" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                       placeholder="Dein Jira API Token" required>
                                <p class="text-sm text-gray-500 mt-1">
                                    <a href="https://id.atlassian.com/manage-profile/security/api-tokens" 
                                       target="_blank" class="text-blue-600 hover:underline">
                                        Hier erstellen <i class="fas fa-external-link-alt ml-1"></i>
                                    </a>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- GitHub-Konfiguration -->
                    <div class="border-b border-gray-200 pb-6">
                        <h2 class="text-2xl font-semibold text-gray-800 mb-4 flex items-center">
                            <i class="fab fa-github text-gray-800 mr-3"></i>
                            GitHub-Konfiguration
                        </h2>
                        
                        <div class="grid md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-code-branch text-gray-400 mr-2"></i>
                                    GitHub Repository
                                </label>
                                <input type="text" name="github_repository" value="{{ old('github_repository') }}" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                       placeholder="benutzername/repository-name" required>
                                <p class="text-sm text-gray-500 mt-1">Format: benutzername/repository-name</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-key text-gray-400 mr-2"></i>
                                    GitHub Personal Access Token
                                </label>
                                <input type="password" name="github_token" value="{{ old('github_token') }}" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                       placeholder="ghp_xxxxxxxxxxxxxxxxxxxx" required>
                                <p class="text-sm text-gray-500 mt-1">
                                    <a href="https://github.com/settings/tokens/new" 
                                       target="_blank" class="text-blue-600 hover:underline">
                                        Hier erstellen <i class="fas fa-external-link-alt ml-1"></i>
                                    </a>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Aktions-Buttons -->
                    <div class="flex justify-between items-center pt-6">
                        <a href="{{ route('bot.dashboard') }}" 
                           class="px-6 py-3 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                            <i class="fas fa-arrow-left mr-2"></i>
                            Zurück zum Dashboard
                        </a>
                        
                        <button type="submit" 
                                class="px-8 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-semibold">
                            <i class="fas fa-rocket mr-2"></i>
                            Bot erstellen
                        </button>
                    </div>
                </form>
            </div>

            <!-- Hilfe-Sektion -->
            <div class="mt-8 bg-blue-50 rounded-xl p-6">
                <h3 class="text-lg font-semibold text-blue-800 mb-3 flex items-center">
                    <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                    Benötigst du Hilfe?
                </h3>
                <div class="grid md:grid-cols-2 gap-4 text-sm text-blue-700">
                    <div>
                        <h4 class="font-semibold mb-2">📋 Jira API Token:</h4>
                        <ul class="list-disc list-inside space-y-1">
                            <li>Gehe zu Jira → Profil → Sicherheit</li>
                            <li>Erstelle ein neues API Token</li>
                            <li>Kopiere es sofort (wird nur einmal angezeigt)</li>
                        </ul>
                    </div>
                    <div>
                        <h4 class="font-semibold mb-2">🐙 GitHub Token:</h4>
                        <ul class="list-disc list-inside space-y-1">
                            <li>Benötigte Scopes: repo, workflow</li>
                            <li>Token sollte mit 'ghp_' beginnen</li>
                            <li>Mindestens 40 Zeichen lang</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Form-Validierung
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            const submitBtn = form.querySelector('button[type="submit"]');
            
            form.addEventListener('submit', function(e) {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Bot wird erstellt...';
                submitBtn.disabled = true;
            });
        });
    </script>
</body>
</html> 