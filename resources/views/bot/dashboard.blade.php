<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🤖 Octomind Bot Dashboard</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-4xl font-bold text-gray-800">
                    🤖 <span class="text-blue-600">Octomind</span> Dashboard
                </h1>
                <p class="text-gray-600 mt-2">Verwalte deine Jira-Ticket-Bots</p>
            </div>
            <a href="{{ route('bot.create') }}" 
               class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-semibold">
                <i class="fas fa-plus mr-2"></i>
                Neuen Bot erstellen
            </a>
        </div>

        @if (session('success'))
            <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-500 mr-2"></i>
                    <p class="text-green-800">{{ session('success') }}</p>
                </div>
            </div>
        @endif

        <!-- Statistiken -->
        <div class="grid md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Aktive Bots</p>
                        <p class="text-3xl font-bold text-green-600">{{ $activeSessions->count() }}</p>
                    </div>
                    <i class="fas fa-robot text-4xl text-green-500"></i>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Projekte</p>
                        <p class="text-3xl font-bold text-blue-600">{{ $projects->count() }}</p>
                    </div>
                    <i class="fab fa-jira text-4xl text-blue-500"></i>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Repositories</p>
                        <p class="text-3xl font-bold text-gray-600">{{ $repositories->count() }}</p>
                    </div>
                    <i class="fab fa-github text-4xl text-gray-500"></i>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Gesamtstunden</p>
                        <p class="text-3xl font-bold text-purple-600">{{ $activeSessions->sum('purchased_hours') }}</p>
                    </div>
                    <i class="fas fa-clock text-4xl text-purple-500"></i>
                </div>
            </div>
        </div>

        <div class="grid lg:grid-cols-2 gap-8">
            <!-- Aktive Bot-Sessions -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-2xl font-semibold text-gray-800 flex items-center">
                        <i class="fas fa-play-circle text-green-500 mr-3"></i>
                        Aktive Bot-Sessions
                    </h2>
                    <button onclick="loadTickets()" 
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm">
                        <i class="fas fa-sync mr-2"></i>
                        Tickets laden
                    </button>
                </div>

                @if($activeSessions->isEmpty())
                    <div class="text-center py-8">
                        <i class="fas fa-robot text-6xl text-gray-300 mb-4"></i>
                        <p class="text-gray-500">Keine aktiven Bot-Sessions</p>
                        <a href="{{ route('bot.create') }}" 
                           class="inline-block mt-4 px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                            Ersten Bot erstellen
                        </a>
                    </div>
                @else
                    <div class="space-y-4">
                        @foreach($activeSessions as $session)
                            <div class="border border-gray-200 rounded-lg p-4">
                                <div class="flex items-center justify-between mb-3">
                                    <div>
                                        <h3 class="font-semibold text-gray-800">{{ $session->project_key }}</h3>
                                        <p class="text-sm text-gray-600">
                                            {{ $session->consumed_hours }}/{{ $session->purchased_hours }} Stunden verbraucht
                                        </p>
                                    </div>
                                    <div class="flex space-x-2">
                                        <button onclick="stopBot('{{ $session->project_key }}')" 
                                                class="px-3 py-2 bg-red-600 text-white rounded hover:bg-red-700 transition-colors text-sm">
                                            <i class="fas fa-stop mr-1"></i>
                                            Stoppen
                                        </button>
                                        <button onclick="getBotStatus('{{ $session->project_key }}')" 
                                                class="px-3 py-2 bg-gray-600 text-white rounded hover:bg-gray-700 transition-colors text-sm">
                                            <i class="fas fa-info mr-1"></i>
                                            Status
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Fortschrittsbalken -->
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-blue-600 h-2 rounded-full" 
                                         style="width: {{ ($session->consumed_hours / $session->purchased_hours) * 100 }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Projekte & Repositories -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-2xl font-semibold text-gray-800 mb-6 flex items-center">
                    <i class="fas fa-project-diagram text-blue-500 mr-3"></i>
                    Projekte & Repositories
                </h2>

                @if($projects->isEmpty())
                    <div class="text-center py-8">
                        <i class="fab fa-jira text-6xl text-gray-300 mb-4"></i>
                        <p class="text-gray-500">Keine Projekte konfiguriert</p>
                    </div>
                @else
                    <div class="space-y-4">
                        @foreach($projects as $project)
                            <div class="border border-gray-200 rounded-lg p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <h3 class="font-semibold text-gray-800 flex items-center">
                                        <i class="fab fa-jira text-blue-600 mr-2"></i>
                                        {{ $project->key }}
                                    </h3>
                                    <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded text-xs">
                                        {{ $project->name }}
                                    </span>
                                </div>
                                
                                @if($project->repositories->isNotEmpty())
                                    <div class="mt-3">
                                        <p class="text-sm text-gray-600 mb-2">Verknüpfte Repositories:</p>
                                        @foreach($project->repositories as $repo)
                                            <div class="flex items-center text-sm text-gray-700 mb-1">
                                                <i class="fab fa-github mr-2"></i>
                                                {{ $repo->name }}
                                                @if($repo->bot_enabled)
                                                    <span class="ml-2 px-2 py-1 bg-green-100 text-green-800 rounded text-xs">
                                                        Bot aktiv
                                                    </span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="mt-3 flex space-x-2">
                                    <button onclick="startBot('{{ $project->key }}')" 
                                            class="px-3 py-2 bg-green-600 text-white rounded hover:bg-green-700 transition-colors text-sm">
                                        <i class="fas fa-play mr-1"></i>
                                        Bot starten
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Status Modal -->
    <div id="statusModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-xl p-6 max-w-md w-full mx-4">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold">Bot Status</h3>
                <button onclick="closeStatusModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="statusContent">
                <!-- Status content wird hier eingefügt -->
            </div>
        </div>
    </div>

    <script>
        // CSRF Token für AJAX-Requests
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        // Bot starten
        async function startBot(projectKey) {
            try {
                const response = await fetch('/bot/start', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ project_key: projectKey })
                });

                const data = await response.json();
                
                if (data.success) {
                    alert('✅ ' + data.message);
                    location.reload();
                } else {
                    alert('❌ ' + data.message);
                }
            } catch (error) {
                alert('❌ Fehler beim Starten des Bots: ' + error.message);
            }
        }

        // Bot stoppen
        async function stopBot(projectKey) {
            if (!confirm('Bot wirklich stoppen?')) return;

            try {
                const response = await fetch('/bot/stop', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ project_key: projectKey })
                });

                const data = await response.json();
                
                if (data.success) {
                    alert('✅ ' + data.message);
                    location.reload();
                } else {
                    alert('❌ ' + data.message);
                }
            } catch (error) {
                alert('❌ Fehler beim Stoppen des Bots: ' + error.message);
            }
        }

        // Bot Status abrufen
        async function getBotStatus(projectKey) {
            try {
                const response = await fetch(`/bot/status/${projectKey}`);
                const data = await response.json();
                
                document.getElementById('statusContent').innerHTML = `
                    <div class="space-y-3">
                        <div>
                            <span class="font-semibold">Projekt:</span> ${data.project?.name || 'N/A'}
                        </div>
                        <div>
                            <span class="font-semibold">Status:</span> 
                            <span class="px-2 py-1 rounded text-sm ${data.is_running ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">
                                ${data.is_running ? 'Läuft' : 'Gestoppt'}
                            </span>
                        </div>
                        ${data.session ? `
                            <div>
                                <span class="font-semibold">Stunden:</span> 
                                ${data.session.consumed_hours}/${data.session.purchased_hours}
                            </div>
                        ` : ''}
                    </div>
                `;
                
                document.getElementById('statusModal').classList.remove('hidden');
                document.getElementById('statusModal').classList.add('flex');
            } catch (error) {
                alert('❌ Fehler beim Abrufen des Status: ' + error.message);
            }
        }

        // Tickets laden
        async function loadTickets() {
            try {
                const response = await fetch('/bot/load-tickets', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken
                    }
                });

                const data = await response.json();
                
                if (data.success) {
                    alert('✅ ' + data.message);
                } else {
                    alert('❌ ' + data.message);
                }
            } catch (error) {
                alert('❌ Fehler beim Laden der Tickets: ' + error.message);
            }
        }

        // Modal schließen
        function closeStatusModal() {
            document.getElementById('statusModal').classList.add('hidden');
            document.getElementById('statusModal').classList.remove('flex');
        }

        // Modal bei Klick außerhalb schließen
        document.getElementById('statusModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeStatusModal();
            }
        });
    </script>
</body>
</html> 