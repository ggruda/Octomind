# 🐬 MySQL Setup für Octomind (Alternative zu PostgreSQL)

## 🤔 **Warum MySQL wählen?**

Falls du **MySQL** statt PostgreSQL bevorzugst:
- **Vertrauter** für viele Entwickler
- **PHPMyAdmin** funktioniert perfekt
- **Einfachere** Backup/Restore-Prozesse
- **Weit verbreitet** in Hosting-Umgebungen

## 🚀 **MySQL-Setup starten:**

```bash
# 1. Stoppe aktuelle PostgreSQL-Services
docker-compose down

# 2. Starte mit MySQL-Konfiguration
docker-compose -f docker-compose-mysql.yml up -d

# 3. Umgebungsvariablen für MySQL setzen
cp docker/env.mysql .env.mysql
cat .env.mysql >> .env

# 4. Migrationen ausführen
docker-compose -f docker-compose-mysql.yml exec octomind-bot php artisan migrate
```

## 🔗 **URLs mit MySQL:**

| Service | URL | Credentials |
|---------|-----|-------------|
| **PHPMyAdmin** | http://localhost:8081 | octomind / octomind_password |
| **Mailpit** | http://localhost:8027 | - |
| **MySQL DB** | localhost:3306 | octomind / octomind_password |

## ⚖️ **PostgreSQL vs MySQL Vergleich:**

### **PostgreSQL (Standard):**
✅ **Besserer JSON-Support**  
✅ **Erweiterte Indizierung**  
✅ **Enterprise-Features**  
✅ **Strikte ACID-Compliance**  
❌ **Komplexer für Einsteiger**  

### **MySQL (Alternative):**
✅ **Einfacher zu verwalten**  
✅ **PHPMyAdmin-Support**  
✅ **Weit verbreitet**  
✅ **Schnellere einfache Queries**  
❌ **Schwächerer JSON-Support**  

## 🔄 **Zurück zu PostgreSQL:**

```bash
# MySQL stoppen
docker-compose -f docker-compose-mysql.yml down

# PostgreSQL starten  
docker-compose up -d
```

## ⚠️ **Wichtige Hinweise:**

1. **JSON-Features:** Einige erweiterte JSON-Queries funktionieren in MySQL anders
2. **Migrationen:** Alle Laravel-Migrationen sind kompatibel
3. **Performance:** Für JSON-lastige Queries ist PostgreSQL schneller
4. **Monitoring:** Grafana-Dashboards sind für PostgreSQL optimiert

## 🎯 **Empfehlung:**

- **Development:** MySQL (wenn du PHPMyAdmin bevorzugst)
- **Production:** PostgreSQL (für bessere Performance und Features)

**Du kannst jederzeit zwischen beiden wechseln!** 