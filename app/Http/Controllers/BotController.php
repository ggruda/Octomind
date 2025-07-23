<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use App\Models\Project;
use App\Models\Repository;
use App\Models\BotSession;

class BotController extends Controller
{
    /**
     * Zeige die Bot-Erstellungsseite
     */
    public function create()
    {
        return view('bot.create');
    }

    /**
     * Zeige Bot-Dashboard
     */
    public function dashboard()
    {
        $projects = Project::with('repositories')->get();
        $repositories = Repository::with('project')->get();
        $activeSessions = BotSession::where('status', 'active')->get();
        
        return view('bot.dashboard', compact('projects', 'repositories', 'activeSessions'));
    }

    /**
     * Erstelle einen neuen Bot
     */
    public function store(Request $request)
    {
        $request->validate([
            'jira_base_url' => 'required|url',
            'jira_username' => 'required|email',
            'jira_api_token' => 'required|string',
            'jira_project_key' => 'required|string|max:10',
            'github_token' => 'required|string',
            'github_repository' => 'required|string',
            'bot_name' => 'required|string|max:255',
        ]);

        try {
            // 1. Projekt importieren
            Artisan::call('octomind:project import', [
                'project_key' => $request->jira_project_key,
                '--jira-base-url' => $request->jira_base_url,
                '--name' => $request->bot_name,
            ]);

            // 2. Repository importieren
            Artisan::call('octomind:repository import', [
                'repository' => $request->github_repository,
                '--provider' => 'github',
                '--bot-enabled' => true,
            ]);

            // 3. SSH Keys initialisieren
            Artisan::call('octomind:ssh-keys init');

            // 4. Repository klonen
            Artisan::call('octomind:repository clone', [
                'repository' => $request->github_repository,
            ]);

            // 5. Repository mit Projekt verknüpfen
            Artisan::call('octomind:project attach-repo', [
                'project_key' => $request->jira_project_key,
                'repository' => $request->github_repository,
            ]);

            // 6. Bot-Session erstellen
            Artisan::call('octomind:bot:create-session', [
                'project_key' => $request->jira_project_key,
                '--hours' => 10, // Standard: 10 Stunden
            ]);

            return redirect()->route('bot.dashboard')
                ->with('success', 'Bot wurde erfolgreich erstellt und ist bereit!');

        } catch (\Exception $e) {
            Log::error('Bot creation failed: ' . $e->getMessage());
            
            return back()
                ->withInput()
                ->withErrors(['error' => 'Fehler beim Erstellen des Bots: ' . $e->getMessage()]);
        }
    }

    /**
     * Starte einen Bot
     */
    public function start(Request $request)
    {
        $request->validate([
            'project_key' => 'required|string',
        ]);

        try {
            Artisan::call('octomind:bot:start', [
                'project_key' => $request->project_key,
                '--daemon' => true,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bot wurde gestartet!'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Fehler beim Starten: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Stoppe einen Bot
     */
    public function stop(Request $request)
    {
        $request->validate([
            'project_key' => 'required|string',
        ]);

        try {
            Artisan::call('octomind:bot:stop', [
                'project_key' => $request->project_key,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bot wurde gestoppt!'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Fehler beim Stoppen: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lade Tickets
     */
    public function loadTickets(Request $request)
    {
        try {
            Artisan::call('octomind:load-tickets');

            return response()->json([
                'success' => true,
                'message' => 'Tickets wurden geladen!'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Fehler beim Laden der Tickets: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bot-Status abrufen
     */
    public function status($projectKey)
    {
        try {
            $project = Project::where('key', $projectKey)->first();
            $session = BotSession::where('project_key', $projectKey)
                ->where('status', 'active')
                ->first();

            return response()->json([
                'project' => $project,
                'session' => $session,
                'is_running' => $session && $session->status === 'active',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Fehler beim Abrufen des Status: ' . $e->getMessage()
            ], 500);
        }
    }
} 