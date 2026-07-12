/**
 * PreDB WhatsApp Bot
 * ==================
 * 
 * Benötigt: Node.js (https://nodejs.org)
 * 
 * Installation:
 *   1. Diese Datei speichern (z.B. als whatsapp-bot.js)
 *   2. Terminal/CMD öffnen und ins Verzeichnis wechseln
 *   3. npm install whatsapp-web.js qrcode-terminal
 *   4. node whatsapp-bot.js
 *   5. QR-Code mit WhatsApp scannen (WhatsApp > Einstellungen > Gekoppelte Geräte)
 *   6. Fertig!
 * 
 * Befehle (in WhatsApp):
 *   !latest [n]  - Letzte n Releases (max 20)
 *   !search <x>  - Suche nach Releases
 *   !stats       - Datenbank-Statistiken
 *   !help        - Hilfe anzeigen
 */

const { Client, LocalAuth } = require('whatsapp-web.js');
const qrcode = require('qrcode-terminal');

// ============================================================
// KONFIGURATION
// ============================================================

const API_BASE = 'https://predb.dnsabr.com/api.php';
const PREFIX = '!';
const BOT_NAME = 'PreBot';
const ADMIN_NUMBER = ''; // Optional: Deine Nummer für Admin-Cmds (z.B. '491765551234567@c.us')

// ============================================================
// BOT START
// ============================================================

const client = new Client({
    authStrategy: new LocalAuth(),
    puppeteer: { headless: true, args: ['--no-sandbox', '--disable-setuid-sandbox'] }
});

// QR-Code im Terminal anzeigen
client.on('qr', qr => {
    console.clear();
    console.log('╔══════════════════════════════════════╗');
    console.log('║     PreDB WhatsApp Bot - Login       ║');
    console.log('╚══════════════════════════════════════╝');
    console.log('');
    console.log('📱 Scanne den QR-Code mit WhatsApp:');
    console.log('   WhatsApp > Einstellungen > Gekoppelte Geräte');
    console.log('');
    qrcode.generate(qr, { small: true });
});

// Bereit
client.on('ready', () => {
    console.log(`\n✅ ${BOT_NAME} ist bereit!`);
    console.log(`   Angemeldet als: ${client.info.pushname || client.info.me}`);
    console.log(`   Prefix: ${PREFIX} (z.B. ${PREFIX}help)`);
    console.log('');
});

// Authentifiziert
client.on('authenticated', () => {
    console.log('🔑 Authentifiziert!');
});

// Auth-Fehler
client.on('auth_failure', msg => {
    console.error('❌ Authentifizierung fehlgeschlagen:', msg);
    console.log('💡 Lösche das "whatsapp-auth"-Verzeichnis und starte neu.');
});

// Nachrichten empfangen
client.on('message', async msg => {
    // Nur Text-Nachrichten mit Prefix beachten
    if (!msg.body || !msg.body.startsWith(PREFIX)) return;
    
    // Nur von Personen (keine Gruppen-Chats, außer Bot wird direkt erwähnt)
    const isGroup = msg.from.endsWith('@g.us');
    const isMentioned = isGroup && msg.body.includes('@' + client.info.me);
    
    // In Gruppen nur reagieren wenn Bot direkt angesprochen wird
    if (isGroup && !isMentioned) return;
    
    // Admin-Check (optional)
    const isAdmin = ADMIN_NUMBER && msg.from === ADMIN_NUMBER;
    
    // Command parsen
    const cmdFull = msg.body.substring(PREFIX.length).trim();
    const parts = cmdFull.split(/ (.+)/);
    const command = parts[0].toLowerCase();
    const args = parts[1] || '';
    
    // "Bot" im Text entfernen für Gruppen-Mentions
    // z.B. "!stats @PreBot" -> "!stats"
    
    console.log(`📩 ${msg.from}: ${cmdFull}`);
    
    try {
        let response = '';
        
        switch (command) {
            case 'latest':
                response = await cmdLatest(args);
                break;
            case 'search':
                response = await cmdSearch(args);
                break;
            case 'stats':
                response = await cmdStats();
                break;
            case 'help':
                response = cmdHelp();
                break;
            default:
                response = `❌ Unbekannter Befehl "${command}". ${PREFIX}help für Hilfe.`;
        }
        
        await msg.reply(response);
        console.log(`📤 Antwort gesendet (${response.length} Zeichen)`);
        
    } catch (err) {
        console.error('❌ Fehler:', err.message);
        await msg.reply('❌ Interner Fehler: ' + err.message);
    }
});

// ============================================================
// COMMANDS
// ============================================================

/**
 * API aufrufen
 */
async function apiFetch(action, params = {}) {
    const url = new URL(API_BASE);
    url.searchParams.set('action', action);
    for (const [key, val] of Object.entries(params)) {
        url.searchParams.set(key, val);
    }
    
    const res = await fetch(url.toString());
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
}

/**
 * !latest [n]
 */
async function cmdLatest(args) {
    const count = Math.min(20, Math.max(1, parseInt(args) || 5));
    const data = await apiFetch('latest', { limit: count });
    
    if (!data.success || !data.data.length) {
        return '❌ Keine Releases gefunden.';
    }
    
    let response = `📦 *Letzte ${data.count} Releases:*\n\n`;
    
    for (const r of data.data) {
        const cat = r.category_name || 'Other';
        const group = r.group_name || '?';
        const size = r.size || '-';
        const time = new Date(r.created_at).toLocaleString('de-DE', {
            hour: '2-digit', minute: '2-digit'
        });
        
        response += `▫️ *${r.name}*\n`;
        response += `   📁 ${cat} · 👤 ${group} · 💾 ${size} · 🕐 ${time}\n\n`;
    }
    
    response += `🔗 ${API_BASE.replace('/api.php', '')}`;
    return response;
}

/**
 * !search <begriff>
 */
async function cmdSearch(args) {
    if (!args || args.length < 2) {
        return '❌ Suchbegriff zu kurz (min. 2 Zeichen).\nUsage: `!search beatport`';
    }
    
    const data = await apiFetch('search', { q: args, limit: 5 });
    
    if (!data.success || !data.data.length) {
        return `🔍 Keine Releases gefunden für *"${args}"*`;
    }
    
    let response = `🔍 *Suche nach "${args}"* (${data.count} Treffer):\n\n`;
    
    for (const r of data.data) {
        const cat = r.category_name || 'Other';
        const group = r.group_name || '?';
        const size = r.size || '-';
        
        response += `▫️ *${r.name}*\n`;
        response += `   📁 ${cat} · 👤 ${group} · 💾 ${size}\n\n`;
    }
    
    return response;
}

/**
 * !stats
 */
async function cmdStats() {
    const data = await apiFetch('stats');
    
    if (!data.success) {
        return '❌ Konnte Statistiken nicht laden.';
    }
    
    const s = data.data;
    
    return `📊 *PreDB Statistiken*\n\n` +
        `📦 Releases: *${formatNumber(s.total_releases)}*\n` +
        `👥 Groups: *${formatNumber(s.total_groups)}*\n` +
        `⏰ Letzte 24h: *${formatNumber(s.last_24h)}*\n` +
        `⏰ Letzte 1h: *${formatNumber(s.last_1h)}*\n\n` +
        `🔗 predb.dnsabr.com`;
}

/**
 * !help
 */
function cmdHelp() {
    return `🤖 *PreDB WhatsApp Bot*\n\n` +
        `*Befehle:*\n` +
        `┌────────────────────────────────────────┐\n` +
        `│ ${PREFIX}latest [n]  Letzte n Releases     │\n` +
        `│ ${PREFIX}search <x>  Nach Releases suchen  │\n` +
        `│ ${PREFIX}stats       Datenbank-Statistiken  │\n` +
        `│ ${PREFIX}help        Diese Hilfe            │\n` +
        `└────────────────────────────────────────┘\n\n` +
        `*Beispiele:*\n` +
        `▫️ ${PREFIX}latest 10\n` +
        `▫️ ${PREFIX}search beatport\n` +
        `▫️ ${PREFIX}stats\n\n` +
        `🌐 predb.dnsabr.com`;
}

/**
 * Zahl formatieren
 */
function formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

// ============================================================
// START
// ============================================================

console.log('╔══════════════════════════════════════╗');
console.log('║     PreDB WhatsApp Bot v1.0          ║');
console.log('╚══════════════════════════════════════╝');
console.log('');
console.log('🔧 Initialisiere...');

client.initialize();
