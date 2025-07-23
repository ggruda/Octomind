# 🔐 SSH-Key-Management für Octomind Bot

## 🎯 **Warum SSH-Keys?**

Der Octomind Bot verwendet SSH-Keys statt HTTPS-Token für Git-Operationen aus folgenden Gründen:

- **🔒 Sicherheit:** Private Keys sind sicherer als Personal Access Tokens
- **🚀 Performance:** SSH ist oft schneller als HTTPS
- **🔄 Rotation:** Keys können einfach rotiert werden ohne Token-Updates
- **📊 Audit:** Bessere Nachverfolgbarkeit von Bot-Aktivitäten
- **🛡️ Isolation:** Jeder Bot hat seine eigenen, eindeutigen Keys

---

## 🚀 **Quick Start**

### **1. SSH-Keys initialisieren:**
```bash
# Docker
docker-compose exec octomind-bot php artisan octomind:ssh-keys init

# Lokal
php artisan octomind:ssh-keys init
```

### **2. Public Key zu Repositories hinzufügen:**
Der Befehl zeigt dir automatisch die Anweisungen für GitHub, GitLab und Bitbucket.

### **3. Verbindung testen:**
```bash
php artisan octomind:ssh-keys test
```

---

## 📋 **Detaillierte Anweisungen**

### **🔧 SSH-Keys initialisieren**

```bash
# Neue Keys generieren
php artisan octomind:ssh-keys init

# Keys forciert neu generieren (überschreibt bestehende)
php artisan octomind:ssh-keys init --force
```

**Output-Beispiel:**
```
🔐 Octomind Bot SSH Key Management
=====================================

🔧 Initialisiere SSH-Keys...
✅ SSH-Keys erfolgreich initialisiert!

🚨 WICHTIG: Deploy Keys zu Repositories hinzufügen!

🔑 Key-Details:
   Fingerprint: SHA256:abc123def456...

📋 Public Key (zum Kopieren):
ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAACAQ... octomind-bot@hostname
```

### **📊 Status prüfen**

```bash
php artisan octomind:ssh-keys status
```

**Output-Beispiel:**
```
📊 SSH-Key-Status:

Status: ✅ Konfiguriert
Keys: ✅ Vorhanden

📁 Pfade:
   Private Key: /var/www/octomind/storage/app/ssh/octomind_bot_rsa
   Public Key:  /var/www/octomind/storage/app/ssh/octomind_bot_rsa.pub
   SSH Dir:     /var/www/octomind/storage/app/ssh

🔑 Key-Details:
   Fingerprint: SHA256:abc123def456...

📋 Public Key:
ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAACAQ...
```

### **🧪 Verbindungen testen**

```bash
php artisan octomind:ssh-keys test
```

**Output-Beispiel:**
```
🧪 Teste SSH-Verbindungen...

✅ github.com: SSH-Verbindung erfolgreich
✅ gitlab.com: SSH-Verbindung erfolgreich
❌ bitbucket.org: SSH-Verbindung fehlgeschlagen

📊 Ergebnis: 2/3 Verbindungen erfolgreich
```

### **🔄 Keys rotieren**

```bash
php artisan octomind:ssh-keys rotate
```

**Wann rotieren?**
- Sicherheitsbedenken
- Regelmäßige Wartung (z.B. alle 6 Monate)
- Nach Personalwechsel
- Bei Kompromittierung

---

## 🏗️ **Deploy Keys zu Repositories hinzufügen**

### **🐙 GitHub**

1. **Repository öffnen** → Settings → Deploy keys
2. **"Add deploy key"** klicken
3. **Titel:** `Octomind Bot`
4. **Key:** Den Public Key einfügen
5. **✅ "Allow write access"** aktivieren
6. **"Add key"** klicken

### **🦊 GitLab**

1. **Repository öffnen** → Settings → Repository → Deploy Keys
2. **Titel:** `Octomind Bot`
3. **Key:** Den Public Key einfügen
4. **✅ "Grant write permissions to this key"** aktivieren
5. **"Add key"** klicken

### **🪣 Bitbucket**

1. **Repository öffnen** → Repository settings → Access keys
2. **"Add key"** klicken
3. **Label:** `Octomind Bot`
4. **Key:** Den Public Key einfügen
5. **"Add key"** klicken

---

## 🔧 **Technische Details**

### **Key-Generierung:**
```bash
ssh-keygen -t rsa -b 4096 -f /storage/app/ssh/octomind_bot_rsa -N "" -C "octomind-bot@hostname"
```

### **SSH-Konfiguration:**
```
# /storage/app/ssh/config
Host github.com
    HostName github.com
    User git
    IdentityFile /storage/app/ssh/octomind_bot_rsa
    IdentitiesOnly yes
    StrictHostKeyChecking no
    UserKnownHostsFile /dev/null

Host gitlab.com
    HostName gitlab.com
    User git
    IdentityFile /storage/app/ssh/octomind_bot_rsa
    IdentitiesOnly yes
    StrictHostKeyChecking no
    UserKnownHostsFile /dev/null
```

### **Git-Integration:**
```bash
# Umgebungsvariable für Git
export GIT_SSH_COMMAND="ssh -i /storage/app/ssh/octomind_bot_rsa -o StrictHostKeyChecking=no"

# Git-Befehle verwenden automatisch SSH
git clone git@github.com:user/repo.git
git push origin main
```

### **Dateiberechtigungen:**
```bash
chmod 700 /storage/app/ssh/                    # SSH-Verzeichnis
chmod 600 /storage/app/ssh/octomind_bot_rsa    # Private Key
chmod 644 /storage/app/ssh/octomind_bot_rsa.pub # Public Key
chmod 600 /storage/app/ssh/config              # SSH-Config
```

---

## 🐳 **Docker-Integration**

### **SSH-Volume:**
```yaml
# docker-compose.yml
volumes:
  - bot-ssh-keys:/var/www/octomind/storage/app/ssh
```

### **Container-Setup:**
```dockerfile
# SSH-Client installiert
RUN apk add --no-cache openssh-client

# SSH-Verzeichnis erstellen
RUN mkdir -p /var/www/octomind/storage/app/ssh
```

### **Persistent Storage:**
SSH-Keys werden persistent im Docker-Volume gespeichert und überleben Container-Neustarts.

---

## 🔍 **Debugging**

### **SSH-Verbindung manuell testen:**
```bash
# Docker
docker-compose exec octomind-bot ssh -i /var/www/octomind/storage/app/ssh/octomind_bot_rsa git@github.com

# Lokal
ssh -i storage/app/ssh/octomind_bot_rsa git@github.com
```

### **Git mit SSH debuggen:**
```bash
# Verbose SSH-Output
GIT_SSH_COMMAND="ssh -v -i /path/to/key" git clone git@github.com:user/repo.git

# Git-Trace aktivieren
GIT_TRACE=1 git clone git@github.com:user/repo.git
```

### **Häufige Probleme:**

#### **❌ "Permission denied (publickey)"**
- **Lösung:** Deploy Key nicht hinzugefügt oder falsche Berechtigungen
- **Check:** `php artisan octomind:ssh-keys test`

#### **❌ "Host key verification failed"**
- **Lösung:** `StrictHostKeyChecking no` in SSH-Config
- **Check:** SSH-Config prüfen

#### **❌ "Could not open a connection to your authentication agent"**
- **Lösung:** SSH-Agent nicht nötig, da direkte Key-Angabe
- **Check:** `GIT_SSH_COMMAND` korrekt gesetzt

---

## 📊 **Monitoring & Logs**

### **SSH-Operationen werden geloggt:**
```bash
[INFO] SSH-Keys erfolgreich initialisiert
[DEBUG] SSH-Konfiguration erstellt: /storage/app/ssh/config
[INFO] Repository erfolgreich geklont via SSH: git@github.com:user/repo.git
[DEBUG] Führe Git-Befehl mit SSH aus: push origin main
```

### **Key-Rotation-Logs:**
```bash
[INFO] Rotiere SSH-Keys
[INFO] Alte Keys gesichert: /storage/app/ssh/backup_2024-01-15_14-30-00
[INFO] Neue SSH-Keys generiert
[WARNING] ⚠️ NEUE SSH-KEYS GENERIERT! Bitte Deploy Keys aktualisieren
```

---

## 🛡️ **Sicherheit**

### **Best Practices:**
- ✅ **Keys regelmäßig rotieren** (alle 6 Monate)
- ✅ **Deploy Keys statt Personal Access Tokens** verwenden
- ✅ **Write-Access nur wenn nötig** aktivieren
- ✅ **Keys-Backup** in sicherem Storage
- ✅ **Monitoring** von SSH-Aktivitäten

### **Key-Management:**
- **Private Keys** bleiben immer auf dem Bot-Server
- **Public Keys** werden zu Repository-Deploy-Keys
- **Automatische Backup** bei Key-Rotation
- **Fingerprint-Tracking** für Audit-Zwecke

---

## ✅ **Zusammenfassung**

### **🎯 Setup-Workflow:**
1. **`php artisan octomind:ssh-keys init`** - Keys generieren
2. **Public Key kopieren** und zu Repository-Deploy-Keys hinzufügen
3. **`php artisan octomind:ssh-keys test`** - Verbindung testen
4. **Bot starten** - SSH funktioniert automatisch

### **🔄 Wartung:**
- **Status prüfen:** `php artisan octomind:ssh-keys status`
- **Verbindung testen:** `php artisan octomind:ssh-keys test`
- **Keys rotieren:** `php artisan octomind:ssh-keys rotate`

### **🚀 Vorteile:**
- **🔒 Sicherer** als Token-basierte Authentifizierung
- **⚡ Schneller** SSH-basierte Git-Operationen
- **🔄 Einfache** Key-Rotation und -Management
- **📊 Besseres** Monitoring und Audit-Trail

**Der Bot ist jetzt bereit für sichere SSH-basierte Git-Operationen!** 🎉 