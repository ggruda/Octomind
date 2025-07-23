<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🤖 Octomind Bot Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 min-h-screen" x-data="dashboard()">
    <!-- Header -->
    <header class="bg-white shadow-lg border-b-4 border-blue-500">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-6">
                <div class="flex items-center">
                    <div class="text-3xl mr-3">🤖</div>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Octomind Bot</h1>
                        <p class="text-sm text-gray-600">AI-Powered Automation System</p>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="flex items-center">
                        <div class="w-3 h-3 rounded-full mr-2" 
                             :class="botStatus === 'healthy' ? 'bg-green-500' : 'bg-red-500'"></div>
                        <span class="text-sm font-medium" x-text="botStatus"></span>
                    </div>
                    <button @click="refreshData()" 
                            class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-sync-alt mr-2"></i>Aktualisieren
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Status Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Bot Status -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="text-2xl mr-3">
                        <i class="fas fa-robot" :class="botEnabled ? 'text-green-500' : 'text-red-500'"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-600">Bot Status</p>
                        <p class="text-lg font-semibold" x-text="botEnabled ? 'Aktiviert' : 'Deaktiviert'"></p>
                    </div>
                </div>
            </div>

            <!-- Simulation Mode -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="text-2xl mr-3">
                        <i class="fas fa-flask" :class="simulationMode ? 'text-yellow-500' : 'text-blue-500'"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-600">Modus</p>
                        <p class="text-lg font-semibold" x-text="simulationMode ? 'Simulation' : 'Live'"></p>
                    </div>
                </div>
            </div>

            <!-- Health Score -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="text-2xl mr-3">
                        <i class="fas fa-heart-pulse text-red-500"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-600">Gesundheit</p>
                        <p class="text-lg font-semibold" x-text="healthScore + '%'"></p>
                    </div>
                </div>
            </div>

            <!-- Processed Tickets -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="text-2xl mr-3">
                        <i class="fas fa-ticket-alt text-green-500"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-600">Heute verarbeitet</p>
                        <p class="text-lg font-semibold" x-text="processedTickets"></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts and Details -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Health Check Details -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold">Gesundheitschecks</h3>
                </div>
                <div class="p-6">
                    <template x-for="(check, name) in healthChecks" :key="name">
                        <div class="flex items-center justify-between py-3 border-b border-gray-100 last:border-b-0">
                            <div class="flex items-center">
                                <div class="w-3 h-3 rounded-full mr-3" 
                                     :class="check.status === 'healthy' ? 'bg-green-500' : 
                                            check.status === 'warning' ? 'bg-yellow-500' : 'bg-red-500'"></div>
                                <span class="font-medium capitalize" x-text="name.replace('_', ' ')"></span>
                            </div>
                            <span class="text-sm text-gray-600" x-text="check.message"></span>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Recent Logs -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-semibold">Aktuelle Logs</h3>
                    <a href="http://localhost:8080" target="_blank" 
                       class="text-blue-500 hover:text-blue-600 text-sm">
                        <i class="fas fa-external-link-alt mr-1"></i>Alle Logs
                    </a>
                </div>
                <div class="p-6 max-h-96 overflow-y-auto">
                    <template x-for="log in recentLogs" :key="log.id">
                        <div class="py-2 border-b border-gray-100 last:border-b-0">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium" 
                                      :class="log.level === 'error' ? 'text-red-600' : 
                                             log.level === 'warning' ? 'text-yellow-600' : 'text-gray-600'"
                                      x-text="log.level.toUpperCase()"></span>
                                <span class="text-xs text-gray-500" x-text="formatDate(log.created_at)"></span>
                            </div>
                            <p class="text-sm text-gray-800 mt-1" x-text="log.message"></p>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="mt-8 bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold mb-4">Quick Actions</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <a href="http://localhost:3000" target="_blank" 
                   class="flex items-center justify-center p-4 border-2 border-blue-200 rounded-lg hover:border-blue-400 transition">
                    <i class="fas fa-chart-line text-blue-500 mr-3"></i>
                    <span>Monitoring Dashboard</span>
                </a>
                <a href="http://localhost:8080" target="_blank"
                   class="flex items-center justify-center p-4 border-2 border-green-200 rounded-lg hover:border-green-400 transition">
                    <i class="fas fa-file-alt text-green-500 mr-3"></i>
                    <span>Live Logs</span>
                </a>
                <button @click="downloadConfig()"
                        class="flex items-center justify-center p-4 border-2 border-purple-200 rounded-lg hover:border-purple-400 transition">
                    <i class="fas fa-download text-purple-500 mr-3"></i>
                    <span>Config Export</span>
                </button>
            </div>
        </div>
    </main>

    <script>
        function dashboard() {
            return {
                botEnabled: {{ $bot_enabled ? 'true' : 'false' }},
                simulationMode: {{ $simulation_mode ? 'true' : 'false' }},
                botStatus: 'loading',
                healthScore: 0,
                processedTickets: 0,
                healthChecks: {},
                recentLogs: [],

                init() {
                    this.loadData();
                    // Auto-refresh every 30 seconds
                    setInterval(() => this.loadData(), 30000);
                },

                async loadData() {
                    try {
                        // Load health status
                        const statusResponse = await fetch('/api/status');
                        const statusData = await statusResponse.json();
                        this.botStatus = statusData.overall_status;
                        this.healthScore = statusData.overall_score;
                        this.healthChecks = statusData.checks;

                        // Load metrics
                        const metricsResponse = await fetch('/api/metrics');
                        const metricsData = await metricsResponse.json();
                        this.processedTickets = metricsData.processed_tickets_today;

                        // Load recent logs
                        const logsResponse = await fetch('/api/logs');
                        const logsData = await logsResponse.json();
                        this.recentLogs = logsData.slice(0, 10);

                    } catch (error) {
                        console.error('Failed to load data:', error);
                        this.botStatus = 'error';
                    }
                },

                refreshData() {
                    this.loadData();
                },

                formatDate(dateString) {
                    return new Date(dateString).toLocaleString('de-DE');
                },

                async downloadConfig() {
                    try {
                        const response = await fetch('/api/config-check');
                        const data = await response.json();
                        const blob = new Blob([JSON.stringify(data, null, 2)], 
                                            { type: 'application/json' });
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = 'octomind-config.json';
                        a.click();
                        URL.revokeObjectURL(url);
                    } catch (error) {
                        alert('Config-Export fehlgeschlagen: ' + error.message);
                    }
                }
            }
        }
    </script>
</body>
</html> 