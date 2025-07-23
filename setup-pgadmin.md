# 🗄️ pgAdmin Setup für Octomind

## 📍 **Zugriff:**
- **URL:** http://localhost:8082
- **Email:** admin@octomind.com
- **Passwort:** octomind_admin

## 🔗 **Datenbank-Server hinzufügen:**

### **1. Nach dem Login:**
- Rechtsklick auf "Servers" → "Register" → "Server..."

### **2. General Tab:**
- **Name:** Octomind Database

### **3. Connection Tab:**
- **Host name/address:** database
- **Port:** 5432
- **Maintenance database:** octomind
- **Username:** octomind
- **Password:** octomind_password

### **4. Advanced Tab (optional):**
- **DB restriction:** octomind

## ✅ **Testen:**
Nach dem Hinzufügen solltest du die Octomind-Datenbank mit allen Tabellen sehen:
- bot_sessions
- projects  
- repositories
- tickets
- project_repositories
- etc.

## 🎯 **Nützliche Queries:**
```sql
-- Alle Bot-Sessions anzeigen
SELECT * FROM bot_sessions ORDER BY created_at DESC;

-- Aktive Sessions mit verbleibenden Stunden
SELECT session_id, customer_email, remaining_hours, status 
FROM bot_sessions 
WHERE status = 'active' AND remaining_hours > 0;

-- Tickets nach Status
SELECT status, COUNT(*) as count 
FROM tickets 
GROUP BY status;

-- Projekt-Übersicht
SELECT p.jira_key, p.name, p.bot_enabled, COUNT(t.id) as ticket_count
FROM projects p
LEFT JOIN tickets t ON p.id = t.project_id
GROUP BY p.id, p.jira_key, p.name, p.bot_enabled;
``` 