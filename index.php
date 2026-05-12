<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Is Radio Up?</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 2rem; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #333; margin-bottom: 0; display: inline; }
        .subtitle { color: #666; display: inline; margin-left: 0.75rem; font-size: 0.9rem; }
        .search-row { display: flex; gap: 0.5rem; margin-bottom: 0.5rem; }
        .search-row input { flex: 1; padding: 10px 14px; border: 2px solid #d1d5db; border-radius: 6px; font-size: 1rem; outline: none; }
        .search-row input:focus { border-color: #2563eb; }
        .btn { background: #2563eb; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 1rem; font-weight: 600; }
        .btn:hover { background: #1d4ed8; }
        .btn:disabled { background: #9ca3af; }
        .results-list { margin-bottom: 1rem; }
        .result-item { background: #fff; padding: 10px 14px; margin-bottom: 4px; border-radius: 6px; cursor: pointer; border: 1px solid #e5e7eb; font-size: 0.9rem; }
        .result-item:hover { background: #f0f9ff; border-color: #2563eb; }
        .result-item .name { font-weight: 600; }
        .result-item .meta { color: #666; font-size: 0.8rem; }
        .card { background: #fff; border-radius: 8px; padding: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 1rem; }
        .card h3 { margin: 0 0 1rem; font-size: 1.1rem; }
        .status-banner { padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1rem; font-size: 1.2rem; font-weight: 700; text-align: center; position: relative; }
        .status-up { background: #dcfce7; color: #166534; }
        .status-down { background: #fee2e2; color: #991b1b; }
        .grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.4rem; }
        .grid-item { font-size: 0.9rem; }
        .grid-item .label { color: #666; font-size: 0.8rem; }
        .grid-item .val { font-weight: 600; }
        .val-good { color: #16a34a; }
        .val-warn { color: #d97706; }
        .val-bad { color: #dc2626; }
        .section-title { font-size: 0.85rem; font-weight: 600; color: #475569; margin: 1rem 0 0.5rem; padding-bottom: 4px; border-bottom: 1px solid #e5e7eb; }
        .hidden { display: none; }
        .loading { text-align: center; color: #6366f1; padding: 2rem; font-weight: 500; }
    </style>
</head>
<body>
<div class="container">
    <h1>Is Radio Up?</h1>
    <p class="subtitle">Search by customer or secondary contact name, phone number, IP address or address</p>

    <div class="search-row">
        <input type="text" id="searchInput" placeholder="Customer name, IP, or address..." 
               oninput="debounceSearch()" onkeypress="if(event.key==='Enter') doSearch()">
        <button class="btn" onclick="doSearch()">Search</button>
    </div>

    <div class="results-list hidden" id="resultsList"></div>
    <div class="hidden" id="diagLoading"><div class="loading">Running diagnostics...</div></div>
    <div class="hidden" id="diagResult"></div>
</div>

<script>
const TPLINK_API_URL = <?= json_encode($tpLinkSpeedTestApiUrl) ?>;
let searchTimer = null;
let lastDiagData = null;
let lastLanData = null;
let lastSpeedTestData = null;

function debounceSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(doSearch, 300);
}

async function doSearch() {
    const q = document.getElementById('searchInput').value.trim();
    if (q.length < 2) return;
    const list = document.getElementById('resultsList');
    const diag = document.getElementById('diagResult');
    diag.classList.add('hidden');

    // If it's an IP, go straight to diagnose
    if (/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(q)) {
        list.classList.add('hidden');
        runDiagnose(q);
        return;
    }

    try {
        const resp = await fetch('search.php?q=' + encodeURIComponent(q));
        const results = await resp.json();
        if (results.length === 0) {
            list.innerHTML = '<div class="result-item"><span class="meta">No results found</span></div>';
        } else if (results.length === 1) {
            list.classList.add('hidden');
            runDiagnose(results[0].service_ipv4);
            return;
        } else {
            list.innerHTML = results.map(s =>
                `<div class="result-item" onclick="runDiagnose('${esc(s.service_ipv4)}')">
                    <span class="name">${esc(s.customer_name)}</span> — ${esc(s.service_ipv4)}
                    <div class="meta">${esc(s.service_address || s.customer_address_fallback || '')} · ${esc(s.service_description || '')}</div>
                </div>`
            ).join('');
        }
        list.classList.remove('hidden');
    } catch(e) {
        list.innerHTML = `<div class="result-item"><span class="meta">Search error: ${esc(e.message)}</span></div>`;
        list.classList.remove('hidden');
    }
}

async function runDiagnose(ip) {
    document.getElementById('resultsList').classList.add('hidden');
    document.getElementById('diagLoading').classList.remove('hidden');
    document.getElementById('diagResult').classList.add('hidden');

    try {
        const resp = await fetch('diagnose.php?ip=' + encodeURIComponent(ip));
        const d = await resp.json();
        renderDiagnosis(d);
    } catch(e) {
        document.getElementById('diagResult').innerHTML = `<div class="card"><p style="color:#dc2626">Diagnosis failed: ${esc(e.message)}</p></div>`;
        document.getElementById('diagResult').classList.remove('hidden');
    }
    document.getElementById('diagLoading').classList.add('hidden');
}

function renderDiagnosis(d) {
    lastDiagData = d;
    lastSpeedTestData = null;
    const el = document.getElementById('diagResult');
    const isUp = d.ping;
    const splynx = d.splynx;
    const ac2 = d.aircontrol2;
    const wg = d.wisp_graphing;

    let html = '';

    // Status banner
    // Status banner — link to radio web UI when online
    let webUrl = `https://${esc(d.ip)}`;
    if (ac2 && ac2.ssh_port) {
        // AC2 webUiPort or default 443
        webUrl = `https://${esc(d.ip)}:${ac2.web_port || 443}`;
    } else if (wg) {
        webUrl = `https://${esc(d.ip)}:8443`;
    }
    const ipLink = isUp ? `<a href="${webUrl}" target="_blank" style="color:inherit;text-decoration:underline">${esc(d.ip)}</a>` : esc(d.ip);
    html += `<div class="status-banner ${isUp ? 'status-up' : 'status-down'}">
        ${isUp ? '●' : '○'} ${isUp ? 'ONLINE' : 'OFFLINE'} — ${ipLink}
        ${!isUp ? `<div style="font-size:0.85rem;font-weight:400;margin-top:4px">Last online: ${d.last_online_mins_ago !== null ? formatMinsAgo(d.last_online_mins_ago) : formatLastSeen(d.last_online)}</div>` : ''}
        <button onclick="createTicketFromDiag()" id="createTicketBtn" style="position:absolute;right:1.5rem;top:50%;transform:translateY(-50%);background:#1e293b;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:0.85rem;font-weight:600">Create Ticket</button>
    </div>`;

    // Splynx info
    if (splynx) {
        const custUrl = splynx.customer_id ? `https://customers.wizbiz.net.nz/admin/customers/view?id=${splynx.customer_id}` : null;
        const svcUrl = (splynx.customer_id && splynx.service_id) ? `https://customers.wizbiz.net.nz/admin/customers/view?id=${splynx.customer_id}` : null;
        html += `<div class="card"><h3>Customer</h3><div class="grid">
            ${custUrl ? giRaw('Customer', `<a href="${custUrl}" target="_blank" style="color:#2563eb">${esc(splynx.customer)}</a>`) : gi('Customer', splynx.customer)}
            ${gi('Service', splynx.service_status, statusClass(splynx.service_status))}
            ${gi('Account', splynx.customer_status, statusClass(splynx.customer_status))}
            ${splynx.address ? giRaw('Address', `<a href="https://www.google.com/maps/search/${encodeURIComponent(splynx.address)}" target="_blank" style="color:#2563eb">${esc(splynx.address)}</a>`) : gi('Address', '-')}
            ${splynx.description ? (svcUrl ? giRaw('Description', `<a href="${svcUrl}" target="_blank" style="color:#2563eb">${esc(splynx.description)}</a>`) : gi('Description', splynx.description)) : ''}
            ${splynx.phone ? gi('Phone', splynx.phone) : ''}
            ${splynx.contact_2_name ? gi('Contact 2', splynx.contact_2_name) : ''}
            ${splynx.contact_2_phone ? gi('Contact 2 Ph', splynx.contact_2_phone) : ''}
        </div></div>`;
    }

    // AirControl2
    if (ac2) {
        html += `<div class="card"><h3>AirControl2</h3><div class="grid">
            ${gi('Device', ac2.device_name)}
            ${gi('Model', ac2.model)}
            ${gi('SSID', ac2.ssid || '-')}
            ${gi('Signal', ac2.signal ? ac2.signal + ' dBm' : '-', signalClass(ac2.signal))}
            ${gi('TX Rate', formatRate(ac2.tx_rate))}
            ${gi('RX Rate', formatRate(ac2.rx_rate))}
            ${gi('LAN', formatLanSpeed(ac2.lan_speed) + ' / ' + formatEthStatus(ac2.eth_status), lanClass(ac2.lan_speed, ac2.eth_status))}
            ${gi('Frequency', (ac2.frequency ? ac2.frequency + ' MHz' : '-') + (ac2.channel_width ? ' @ ' + ac2.channel_width + ' MHz' : ''))}
            ${gi('Uptime', formatUptime(ac2.uptime))}
            ${gi('Last Seen', ac2.online ? 'Now' : formatLastSeen(ac2.last_seen))}
        </div></div>`;
    }

    // WISP-graphing
    if (wg) {
        html += `<div class="card"><h3>WISP-graphing (Cambium)</h3><div class="grid">
            ${gi('Device', wg.device_name)}
            ${gi('AP', wg.ap_name)}
            ${gi('Status', wg.status, wg.status === 'online' ? 'val-good' : 'val-bad')}
            ${gi('RSSI UL', wg.rssi_upload ? wg.rssi_upload + ' dBm' : '-', signalClass(wg.rssi_upload))}
            ${gi('RSSI DL', wg.rssi_download ? wg.rssi_download + ' dBm' : '-', signalClass(wg.rssi_download))}
            ${gi('MCS UL', formatMCS(wg.mcs_upload))}
            ${gi('MCS DL', formatMCS(wg.mcs_download))}
            ${gi('Retrans DL', wg.retrans_dl ? wg.retrans_dl + '%' : '-', retransClass(wg.retrans_dl))}
            ${gi('LAN Rate', wg.lan_rate ? wg.lan_rate + ' Mbps' + (wg.last_poll_mins_ago !== null ? ' (' + formatMinsAgo(wg.last_poll_mins_ago) + ')' : '') : '-')}
            ${gi('MAC', wg.mac || '-')}
            ${gi('Last Seen', wg.status === 'online' ? 'Now' : (wg.last_seen_mins_ago !== null ? formatMinsAgo(wg.last_seen_mins_ago) : '-'))}
        </div></div>`;
    }

    if (!ac2 && !wg) {
        html += `<div class="card"><p style="color:#666">Not found in AirControl2 or WISP-graphing</p></div>`;
    }

    // Speed Test card — separate from SNMP, auto-fetches when WISP-graphing is available
    if (d.wisp_graphing_available === true && wg) {
        html += `<div class="card" id="speedTestCard">
            <h3>Speed Test</h3>
            <div id="speedTestContent"><div class="loading" style="padding:0.5rem">Loading speed test data...</div></div>
        </div>`;
    }

    // DHCP/LAN button — AC2 devices via SSH, WISP-graphing devices via SNMP
    if (isUp && ac2) {
        const sshPort = ac2.ssh_port || 22;
        html += `<div class="card" id="dhcpCard">
            <h3>LAN / DHCP Leases</h3>
            <button class="btn" onclick="fetchDhcpLeases('${esc(d.ip)}', ${sshPort})" id="dhcpBtn" style="font-size:0.9rem;padding:8px 16px;">Fetch via SSH</button>
            <div id="dhcpResult" style="margin-top:1rem"></div>
        </div>`;
    } else if (isUp && wg) {
        const comm = wg.snmp_community || 'public';
        html += `<div class="card" id="dhcpCard">
            <h3>LAN / Connected Devices <span style="font-size:0.75rem;color:#666;font-weight:400">(live SNMP query)</span></h3>
            <button class="btn" onclick="fetchSnmpCambium('${esc(d.ip)}', '${esc(comm)}')" id="dhcpBtn" style="font-size:0.9rem;padding:8px 16px;">Fetch via SNMP</button>
            <div id="dhcpResult" style="margin-top:1rem"></div>
        </div>`; 
    }

    el.innerHTML = html;
    el.classList.remove('hidden');

    // Auto-fetch speed test data if WISP-graphing is available
    if (d.wisp_graphing_available === true && wg) {
        fetchSpeedTestData(d.ip);
    }
}

function gi(label, value, cls) {
    return `<div class="grid-item"><div class="label">${label}</div><div class="val ${cls||''}">${esc(value || '-')}</div></div>`;
}
function giRaw(label, html, cls) {
    return `<div class="grid-item"><div class="label">${label}</div><div class="val ${cls||''}">${html}</div></div>`;
}
function statusClass(s) { return s === 'active' ? 'val-good' : s === 'blocked' ? 'val-bad' : s === 'stopped' ? 'val-warn' : ''; }
function signalClass(s) { if (!s) return ''; const v = parseInt(s); return v >= -65 ? 'val-good' : v >= -75 ? 'val-warn' : 'val-bad'; }
function retransClass(r) { if (!r) return ''; const v = parseFloat(r); return v < 5 ? 'val-good' : v < 15 ? 'val-warn' : 'val-bad'; }
function formatRate(r) { if (!r) return '-'; const mbps = parseInt(r) / 1000000; return mbps.toFixed(0) + ' Mbps'; }
function formatThroughput(t) { if (!t) return '-'; const bps = parseInt(t); if (bps > 1000000) return (bps/1000000).toFixed(1) + ' Mbps'; if (bps > 1000) return (bps/1000).toFixed(0) + ' kbps'; return bps + ' bps'; }
function formatLanSpeed(s) {
    if (!s && s !== 0) return '-';
    const v = parseInt(s);
    // AC2 lanSpeed is an enum, not raw Mbps
    const map = {1: 'Unplugged', 4: '10 HD', 8: '10 FD', 20: '100 HD', 34: '10 FD', 36: '100 FD', 40: '1000 FD'};
    if (map[v]) return map[v];
    // Fallback: show raw value
    return v + ' (raw)';
}
function formatEthStatus(s) { if (s === null || s === undefined) return '-'; return parseInt(s) === 1 ? 'UP' : 'DOWN'; }
function lanClass(speed, eth) {
    if (!speed && speed !== 0) return '';
    const v = parseInt(speed);
    if (v === 1 || (eth !== null && parseInt(eth) !== 1)) return 'val-bad';  // Unplugged or DOWN
    if (v === 34) return 'val-warn';  // 10 Mbps
    return '';
}
function formatUptime(u) { if (!u) return '-'; const s = parseInt(u); const d = Math.floor(s/86400); const h = Math.floor((s%86400)/3600); return d > 0 ? d+'d '+h+'h' : h+'h'; }
function formatLastSeen(ls) {
    if (!ls || ls === 'Unknown') return ls || '-';
    if (ls === 'Now (online)') return ls;
    // Parse the timestamp. ISO 8601 with offset (e.g. 2026-04-15T17:30:00+12:00) is handled natively.
    // UTC timestamps end with Z. Plain timestamps without offset use server's UTC offset.
    let d;
    if (ls.includes('+') || ls.includes('Z') || ls.match(/T.*-\d{2}:/)) {
        // Has timezone info — JS can parse directly
        d = new Date(ls);
    } else {
        // No timezone info — assume server local time, append offset from diagnose response
        const offset = (lastDiagData && lastDiagData.server_utc_offset) ? lastDiagData.server_utc_offset : '+12:00';
        d = new Date(ls.replace(' ', 'T') + offset);
    }
    const now = new Date(); const diff = now - d;
    if (isNaN(diff) || diff < 0) return ls;
    const mins = Math.floor(diff/60000); const hrs = Math.floor(mins/60); const days = Math.floor(hrs/24);
    if (days > 0) return days+'d '+hrs%24+'h ago';
    if (hrs > 0) return hrs+'h '+mins%60+'m ago';
    return mins+'m ago';
}
function createTicketFromDiag() {
    if (!lastDiagData) return;
    const d = lastDiagData;
    const splynx = d.splynx;
    const ac2 = d.aircontrol2;
    const wg = d.wisp_graphing;

    const address = splynx?.address || 'Unknown';
    const subject = `FAULT - ${address}`;

    // Build diagnostic message
    const lines = [];
    lines.push('');
    lines.push('');
    lines.push('*** RADIO DIAGNOSTICS ***');
    lines.push(`IP: ${d.ip}`);
    lines.push(`Status: ${d.ping ? 'ONLINE' : 'OFFLINE'}`);
    if (!d.ping) {
        const lastOnline = d.last_online_mins_ago !== null ? formatMinsAgo(d.last_online_mins_ago) : (d.last_online || 'Unknown');
        lines.push(`Last Online: ${lastOnline}`);
    }
    if (splynx) {
        lines.push(`Customer: ${splynx.customer}`);
        lines.push(`Address: ${address}`);
        lines.push(`Service: ${splynx.service_status}, Account: ${splynx.customer_status}`);
        if (splynx.phone) lines.push(`Phone: ${splynx.phone}`);
        if (splynx.contact_2_name) lines.push(`Contact 2: ${splynx.contact_2_name}`);
        if (splynx.contact_2_phone) lines.push(`Contact 2 Ph: ${splynx.contact_2_phone}`);
    }
    if (ac2) {
        lines.push('');
        lines.push(`--- AirControl2 ---`);
        lines.push(`Device: ${ac2.device_name} (${ac2.model})`);
        if (ac2.ssid) lines.push(`SSID: ${ac2.ssid}`);
        lines.push(`Signal: ${ac2.signal || '-'} dBm`);
        lines.push(`TX/RX Rate: ${ac2.tx_rate ? Math.round(ac2.tx_rate/1000000) : '-'}/${ac2.rx_rate ? Math.round(ac2.rx_rate/1000000) : '-'} Mbps`);
        lines.push(`LAN: ${formatLanSpeed(ac2.lan_speed)}, ETH: ${formatEthStatus(ac2.eth_status)}`);
        lines.push(`Firmware: ${ac2.firmware || '-'}`);
        lines.push(`Uptime: ${formatUptime(ac2.uptime)}`);
        lines.push(`Online: ${ac2.online ? 'Yes' : 'No'}`);
    }
    if (wg) {
        lines.push('');
        lines.push(`--- WISP-graphing ---`);
        lines.push(`Device: ${wg.device_name}`);
        lines.push(`AP: ${wg.ap_name}`);
        lines.push(`RSSI UL/DL: ${wg.rssi_upload || '-'}/${wg.rssi_download || '-'} dBm`);
        lines.push(`MCS UL/DL: ${formatMCS(wg.mcs_upload)}/${formatMCS(wg.mcs_download)}`);
        if (wg.retrans_dl) lines.push(`Retrans DL: ${wg.retrans_dl}%`);
        if (wg.lan_rate) lines.push(`LAN Rate: ${wg.lan_rate} Mbps`);
    }

    // Include speed test data if fetched
    if (lastSpeedTestData) {
        lines.push('');
        lines.push('--- Speed Test ---');
        lines.push(`Download: ${lastSpeedTestData.download_mbps !== null && lastSpeedTestData.download_mbps !== undefined ? lastSpeedTestData.download_mbps + ' Mbps' : '-'}`);
        lines.push(`Upload: ${lastSpeedTestData.upload_mbps !== null && lastSpeedTestData.upload_mbps !== undefined ? lastSpeedTestData.upload_mbps + ' Mbps' : '-'}`);
        if (lastSpeedTestData.latency_ms !== null && lastSpeedTestData.latency_ms !== undefined) {
            lines.push(`Latency: ${lastSpeedTestData.latency_ms} ms`);
        }
        if (lastSpeedTestData.timestamp) lines.push(`Tested: ${lastSpeedTestData.timestamp}`);
    }

    // Include DHCP/ARP data if fetched
    if (lastLanData) {
        lines.push('');
        lines.push('--- LAN / Connected Devices ---');
        if (lastLanData.lan) {
            const lan = lastLanData.lan;
            if (lan.lan_speed_formatted) lines.push(`LAN Speed: ${lan.lan_speed_formatted}`);
            if (lan.lan_status_formatted) lines.push(`LAN Status: ${lan.lan_status_formatted}`);
        }
        if (lastLanData.leases && lastLanData.leases.length > 0) {
            lines.push('DHCP Leases:');
            lastLanData.leases.forEach(l => {
                lines.push(`  ${l.ip} - ${l.mac} ${l.vendor || ''} ${l.hostname || ''}`);
            });
        }
        if (lastLanData.arp && lastLanData.arp.length > 0) {
            lines.push('ARP/Connected:');
            lastLanData.arp.forEach(a => {
                lines.push(`  ${a.ip} - ${a.mac} ${a.vendor || ''} ${a.interface || ''}`);
            });
        }
    }

    // Open radio-ticket with pre-populated data
    const params = new URLSearchParams({
        ip: d.ip,
        subject: subject,
        message: lines.join('\n'),
        type: 'fault',
        ac_tag: '',
    });
    window.open('/radio-ticket/?' + params.toString(), '_blank');
}

function formatMCS(v) {
    if (!v && v !== 0) return '-';
    const n = parseInt(v);
    if (n >= 200) return 'DS ' + (n - 200);
    if (n >= 100) return 'SS ' + (n - 100);
    if (n >= 10) return 'DS ' + (n - 10);
    return 'SS ' + n;
}
function formatMinsAgo(mins) {
    if (mins === null || mins === undefined) return '-';
    mins = parseInt(mins);
    if (mins < 1) return 'Just now';
    if (mins < 60) return mins + 'm ago';
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return hrs + 'h ' + (mins % 60) + 'm ago';
    const days = Math.floor(hrs / 24);
    return days + 'd ' + (hrs % 24) + 'h ago';
}
function esc(s) { const d = document.createElement('div'); d.textContent = s||''; return d.innerHTML; }

async function fetchSnmpCambium(ip, community) {
    const btn = document.getElementById('dhcpBtn');
    const result = document.getElementById('dhcpResult');
    btn.disabled = true; btn.textContent = 'Querying SNMP...';

    try {
        const data = await fetch(`snmp_cambium.php?ip=${encodeURIComponent(ip)}&community=${encodeURIComponent(community)}`).then(r => r.json());

        if (data.error) {
            result.innerHTML = `<p style="color:#dc2626">${esc(data.error)}</p>
                <p style="font-size:0.85rem;margin-top:0.5rem"><a href="/WISP-graphing/frontend/" target="_blank" style="color:#2563eb">Open WISP-graphing</a> to check/update the SNMP community string for this subscriber</p>`;
            btn.disabled = false; btn.textContent = 'Retry';
            return;
        }

        let html = '';
        const lan = data.lan || {};

        // LAN status summary
        html += `<div class="grid" style="margin-bottom:1rem">
            ${gi('LAN Speed', lan.lan_speed_formatted || '-')}
            ${gi('LAN Status', lan.lan_status_formatted || '-', lan.lan_status_formatted === 'DOWN' ? 'val-bad' : 'val-good')}
            ${lan.interface ? gi('Interface', lan.interface) : ''}
            ${data.uptime_seconds ? gi('Uptime', formatUptime(data.uptime_seconds)) : ''}
        </div>`;

        // Show all interfaces if multiple
        if (lan.all_interfaces && lan.all_interfaces.length > 1) {
            html += `<div style="margin-bottom:1rem;font-size:0.85rem"><strong>All Interfaces:</strong><br>`;
            lan.all_interfaces.forEach(i => {
                const cls = i.status === 'DOWN' ? 'val-bad' : 'val-good';
                html += `<span style="margin-right:1rem">${esc(i.name)}: <span class="${cls}">${esc(i.status)}</span> ${esc(i.speed)}</span>`;
            });
            html += `</div>`;
        }

        // ARP / connected devices
        let hasTPLink = false;
        if (data.arp && data.arp.length > 0) {
            html += `<table style="width:100%;border-collapse:collapse;font-size:0.85rem">
                <thead><tr style="background:#f1f5f9;text-align:left">
                    <th style="padding:6px 8px">IP</th>
                    <th style="padding:6px 8px">MAC</th>
                    <th style="padding:6px 8px">Vendor</th>
                    <th style="padding:6px 8px">Interface</th>
                </tr></thead><tbody>`;
            data.arp.forEach(a => {
                if (a.vendor && a.vendor.toLowerCase().includes('tp-link systems inc')) hasTPLink = true;
                html += `<tr style="border-bottom:1px solid #e5e7eb">
                    <td style="padding:5px 8px">${esc(a.ip)}</td>
                    <td style="padding:5px 8px;font-family:monospace;font-size:0.8rem">${esc(a.mac)}</td>
                    <td style="padding:5px 8px;font-size:0.8rem;color:#666">${esc(a.vendor || '-')}</td>
                    <td style="padding:5px 8px">${esc(a.interface || '-')}</td>
                </tr>`;
            });
            html += '</tbody></table>';
        } else {
            html += '<p style="color:#666">No connected devices found via SNMP ARP table</p>';
        }

        // TP-Link Speed Test buttons if a TP-Link device is detected
        if (hasTPLink) {
            html += `<div style="margin-top:1rem;padding-top:0.75rem;border-top:1px solid #e5e7eb">
                <strong style="font-size:0.85rem;color:#475569">TP-Link Speed Test</strong>
                <div style="margin-top:0.5rem;display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap">
                    <button class="btn" id="tplinkSpeedTestBtn" onclick="runTpLinkSpeedTest('${esc(ip)}')" style="font-size:0.85rem;padding:6px 14px;background:#f59e0b;color:#000;font-weight:600">TP-Link Speed Test (60s)</button>
                    <button class="btn" id="tplinkHistoryBtn" onclick="fetchTpLinkHistory('${esc(ip)}')" style="font-size:0.85rem;padding:6px 14px;background:#64748b">TP-Link Speed Test History</button>
                    <span id="tplinkSpeedTestStatus" style="font-size:0.85rem;color:#6366f1;font-weight:500"></span>
                </div>
                <div id="tplinkSpeedTestResult" style="margin-top:0.75rem"></div>
            </div>`;
        }

        result.innerHTML = html;
        btn.style.display = 'none';
        lastLanData = data;
    } catch(e) {
        result.innerHTML = `<p style="color:#dc2626">Error: ${esc(e.message)}</p>`;
        btn.disabled = false; btn.textContent = 'Retry';
    }
}

async function fetchSpeedTestData(ip) {
    const content = document.getElementById('speedTestContent');
    if (!content) return;

    try {
        const resp = await fetch(`speed_test.php?action=latest&ip=${encodeURIComponent(ip)}`);
        const speedData = await resp.json();
        content.innerHTML = renderSpeedTestSection(speedData, ip);
    } catch(e) {
        content.innerHTML = `<p style="color:#d97706;font-size:0.85rem">Speed test lookup failed</p>`;
    }
}

function renderSpeedTestSection(speedData, ip) {
    // Store latest for ticket text
    if (speedData && speedData.latest_result) {
        lastSpeedTestData = speedData.latest_result;
    }

    let html = '';

    // Results table (up to 10)
    html += '<div id="speedTestDisplay">';
    if (!speedData || speedData.error) {
        const msg = speedData && speedData.error ? esc(speedData.error) : 'Speed test lookup failed';
        html += `<p style="color:#d97706;font-size:0.85rem">${msg}</p>`;
    } else if (!speedData.results || speedData.results.length === 0) {
        html += '<p style="color:#666;font-size:0.85rem">No Radio Speed Test Results</p>';
    } else {
        html += `<table style="width:100%;border-collapse:collapse;font-size:0.85rem">
            <thead><tr style="background:#f1f5f9;text-align:left">
                <th style="padding:6px 8px">Download</th>
                <th style="padding:6px 8px">Upload</th>
                <th style="padding:6px 8px">Status</th>
                <th style="padding:6px 8px">Source</th>
                <th style="padding:6px 8px">When</th>
            </tr></thead><tbody>`;
        speedData.results.forEach((r, i) => {
            const dlStr = r.download_mbps !== null ? r.download_mbps + ' Mbps' : '-';
            const ulStr = r.upload_mbps !== null ? r.upload_mbps + ' Mbps' : '-';
            const dlCls = r.status === 'success' && r.download_mbps !== null ? 'val-good' : (r.status === 'failed' ? 'val-bad' : '');
            const ulCls = r.status === 'success' && r.upload_mbps !== null ? 'val-good' : (r.status === 'failed' ? 'val-bad' : '');
            const statusCls = r.status === 'success' ? 'val-good' : 'val-bad';
            const bold = i === 0 ? 'font-weight:600;' : '';
            html += `<tr style="border-bottom:1px solid #e5e7eb;${bold}">
                <td style="padding:5px 8px" class="${dlCls}">${esc(dlStr)}</td>
                <td style="padding:5px 8px" class="${ulCls}">${esc(ulStr)}</td>
                <td style="padding:5px 8px" class="${statusCls}">${esc(r.status || '-')}</td>
                <td style="padding:5px 8px;color:#666">${esc(r.source || '-')}</td>
                <td style="padding:5px 8px">${r.timestamp ? formatLastSeen(r.timestamp) : '-'}</td>
            </tr>`;
        });
        html += '</tbody></table>';
    }
    html += '</div>';

    // Action buttons
    html += `<div style="margin-top:0.75rem;display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap">
        <button class="btn" id="runSpeedTestBtn" onclick="runSpeedTest('${esc(ip)}')" style="font-size:0.85rem;padding:6px 14px;background:#7c3aed">Radio Speed Test</button>
        <button class="btn" id="scheduleSpeedTestBtn" onclick="toggleSchedulePicker()" style="font-size:0.85rem;padding:6px 14px;background:#0891b2">Schedule Radio Speed Test</button>
        <span id="speedTestStatus" style="font-size:0.85rem;color:#6366f1;font-weight:500"></span>
    </div>`;

    // Schedule picker (hidden by default)
    html += `<div id="schedulePicker" style="display:none;margin-top:0.75rem;padding:0.75rem;background:#f8fafc;border-radius:6px;border:1px solid #e2e8f0">
        <label style="font-size:0.85rem;color:#475569;font-weight:500">Select date/time:</label>
        <div style="display:flex;gap:0.5rem;align-items:center;margin-top:0.25rem">
            <input type="datetime-local" id="scheduleDateTime" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:4px;font-size:0.85rem">
            <button class="btn" id="confirmScheduleBtn" onclick="submitSchedule('${esc(ip)}')" style="font-size:0.85rem;padding:6px 14px;background:#0891b2">Confirm</button>
            <button onclick="toggleSchedulePicker()" style="font-size:0.85rem;padding:6px 10px;background:none;border:1px solid #d1d5db;border-radius:4px;cursor:pointer;color:#64748b">Cancel</button>
        </div>
        <div id="scheduleMessage" style="margin-top:0.5rem;font-size:0.85rem"></div>
    </div>`;

    return html;
}

async function runSpeedTest(ip) {
    const btn = document.getElementById('runSpeedTestBtn');
    const status = document.getElementById('speedTestStatus');
    const display = document.getElementById('speedTestDisplay');
    btn.disabled = true;
    status.textContent = 'Running speed test...';
    status.style.color = '#6366f1';

    try {
        const resp = await fetch('speed_test.php?action=run', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ip: ip})
        });
        const data = await resp.json();

        if (data.error) {
            status.textContent = esc(data.error);
            status.style.color = '#dc2626';
        } else {
            // Refresh the full speed test data to show updated history
            lastSpeedTestData = data;
            status.textContent = 'Complete — refreshing results...';
            status.style.color = '#16a34a';
            await fetchSpeedTestData(ip);
            status.textContent = 'Complete';
        }
    } catch(e) {
        status.textContent = 'Speed test request failed';
        status.style.color = '#dc2626';
    }
    btn.disabled = false;
}

function toggleSchedulePicker() {
    const picker = document.getElementById('schedulePicker');
    const msg = document.getElementById('scheduleMessage');
    if (picker.style.display === 'none') {
        picker.style.display = 'block';
        // Default to 1 hour from now
        const now = new Date();
        now.setHours(now.getHours() + 1);
        now.setMinutes(0, 0, 0);
        document.getElementById('scheduleDateTime').value = now.toISOString().slice(0, 16);
        msg.innerHTML = '';
    } else {
        picker.style.display = 'none';
    }
}

async function submitSchedule(ip) {
    const dt = document.getElementById('scheduleDateTime').value;
    const msg = document.getElementById('scheduleMessage');
    const confirmBtn = document.getElementById('confirmScheduleBtn');
    if (!dt) {
        msg.innerHTML = '<span style="color:#dc2626">Please select a date and time</span>';
        return;
    }

    confirmBtn.disabled = true;
    msg.innerHTML = '<span style="color:#6366f1">Scheduling...</span>';

    try {
        const resp = await fetch('speed_test.php?action=schedule', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ip: ip, scheduled_time: dt.replace('T', ' ')})
        });
        const data = await resp.json();

        if (data.error) {
            msg.innerHTML = `<span style="color:#dc2626">${esc(data.error)}</span>`;
        } else {
            msg.innerHTML = `<span style="color:#16a34a">Speed test scheduled for ${esc(data.scheduled_time || dt)}</span>`;
            setTimeout(() => { document.getElementById('schedulePicker').style.display = 'none'; }, 3000);
        }
    } catch(e) {
        msg.innerHTML = '<span style="color:#dc2626">Failed to schedule speed test</span>';
    }
    confirmBtn.disabled = false;
}

async function fetchDhcpLeases(ip, sshPort) {
    const btn = document.getElementById('dhcpBtn');
    const result = document.getElementById('dhcpResult');
    btn.disabled = true; btn.textContent = 'Connecting via SSH...';

    try {
        const resp = await fetch(`dhcp_leases.php?ip=${encodeURIComponent(ip)}&ssh_port=${sshPort}`);
        const data = await resp.json();

        if (data.error) {
            result.innerHTML = `<p style="color:#dc2626">${esc(data.error)}</p>`;
            btn.disabled = false; btn.textContent = 'Retry';
            return;
        }

        let html = '';
        let hasTPLink = false;

        // Check leases for TP-Link
        if (data.leases.length > 0) {
            data.leases.forEach(l => {
                if (l.vendor && l.vendor.toLowerCase().includes('tp-link systems inc')) hasTPLink = true;
            });
            html += `<table style="width:100%;border-collapse:collapse;font-size:0.85rem;margin-bottom:1rem">
                <thead><tr style="background:#f1f5f9;text-align:left">
                    <th style="padding:6px 8px">IP</th>
                    <th style="padding:6px 8px">MAC</th>
                    <th style="padding:6px 8px">Vendor</th>
                    <th style="padding:6px 8px">Hostname</th>
                    <th style="padding:6px 8px">Expiry</th>
                </tr></thead><tbody>`;
            data.leases.forEach(l => {
                html += `<tr style="border-bottom:1px solid #e5e7eb">
                    <td style="padding:5px 8px">${esc(l.ip)}</td>
                    <td style="padding:5px 8px;font-family:monospace;font-size:0.8rem">${esc(l.mac)}</td>
                    <td style="padding:5px 8px;font-size:0.8rem;color:#666">${esc(l.vendor || '-')}</td>
                    <td style="padding:5px 8px">${esc(l.hostname || '-')}</td>
                    <td style="padding:5px 8px">${esc(l.expiry)}</td>
                </tr>`;
            });
            html += '</tbody></table>';
        } else {
            html += '<p style="color:#666">No DHCP leases found (radio may not be in NAT/router mode)</p>';
        }

        if (data.arp.length > 0) {
            // Check ARP for TP-Link too
            data.arp.forEach(a => {
                if (a.vendor && a.vendor.toLowerCase().includes('tp-link systems inc')) hasTPLink = true;
            });
            html += `<div style="margin-top:0.5rem"><strong style="font-size:0.85rem;color:#475569">ARP Table</strong></div>
                <table style="width:100%;border-collapse:collapse;font-size:0.85rem;margin-top:0.25rem">
                <thead><tr style="background:#f1f5f9;text-align:left">
                    <th style="padding:6px 8px">IP</th>
                    <th style="padding:6px 8px">MAC</th>
                    <th style="padding:6px 8px">Vendor</th>
                    <th style="padding:6px 8px">Interface</th>
                </tr></thead><tbody>`;
            data.arp.forEach(a => {
                html += `<tr style="border-bottom:1px solid #e5e7eb">
                    <td style="padding:5px 8px">${esc(a.ip)}</td>
                    <td style="padding:5px 8px;font-family:monospace;font-size:0.8rem">${esc(a.mac)}</td>
                    <td style="padding:5px 8px;font-size:0.8rem;color:#666">${esc(a.vendor || '-')}</td>
                    <td style="padding:5px 8px">${esc(a.device)}</td>
                </tr>`;
            });
            html += '</tbody></table>';
        }

        // TP-Link Speed Test buttons if a TP-Link device is detected
        if (hasTPLink) {
            html += `<div style="margin-top:1rem;padding-top:0.75rem;border-top:1px solid #e5e7eb">
                <strong style="font-size:0.85rem;color:#475569">TP-Link Speed Test</strong>
                <div style="margin-top:0.5rem;display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap">
                    <button class="btn" id="tplinkSpeedTestBtn" onclick="runTpLinkSpeedTest('${esc(ip)}')" style="font-size:0.85rem;padding:6px 14px;background:#f59e0b;color:#000;font-weight:600">TP-Link Speed Test (60s)</button>
                    <button class="btn" id="tplinkHistoryBtn" onclick="fetchTpLinkHistory('${esc(ip)}')" style="font-size:0.85rem;padding:6px 14px;background:#64748b">TP-Link Speed Test History</button>
                    <span id="tplinkSpeedTestStatus" style="font-size:0.85rem;color:#6366f1;font-weight:500"></span>
                </div>
                <div id="tplinkSpeedTestResult" style="margin-top:0.75rem"></div>
            </div>`;
        }

        result.innerHTML = html;
        btn.style.display = 'none';
        lastLanData = data;
    } catch(e) {
        result.innerHTML = `<p style="color:#dc2626">Error: ${esc(e.message)}</p>`;
        btn.disabled = false; btn.textContent = 'Retry';
    }
}

// TP-Link Speed Test via API
let tplinkCooldownTimer = null;

async function runTpLinkSpeedTest(radioIp) {
    const btn = document.getElementById('tplinkSpeedTestBtn');
    const status = document.getElementById('tplinkSpeedTestStatus');
    const resultDiv = document.getElementById('tplinkSpeedTestResult');

    btn.disabled = true;
    status.textContent = 'Running TP-Link speed test (~60s)...';
    status.style.color = '#6366f1';

    // Start 70s cooldown
    let cooldown = 70;
    btn.textContent = `TP-Link Speed Test (${cooldown}s)`;
    tplinkCooldownTimer = setInterval(() => {
        cooldown--;
        btn.textContent = `TP-Link Speed Test (${cooldown}s)`;
        if (cooldown <= 0) {
            clearInterval(tplinkCooldownTimer);
            tplinkCooldownTimer = null;
            btn.disabled = false;
            btn.textContent = 'TP-Link Speed Test (60s)';
        }
    }, 1000);

    try {
        const url = TPLINK_API_URL + '/api/speedtest';
        const resp = await fetch(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({device_ip: radioIp})
        });

        if (!resp.ok) {
            const text = await resp.text().catch(() => '');
            status.textContent = `HTTP ${resp.status}: ${text || resp.statusText}`;
            status.style.color = '#dc2626';
            resultDiv.innerHTML = `<p style="font-size:0.8rem;color:#666">POST ${esc(url)} — device_ip: ${esc(radioIp)}</p>`;
            return;
        }

        const data = await resp.json();

        if (data.error) {
            status.textContent = 'Error: ' + (data.error || 'Unknown error');
            status.style.color = '#dc2626';
        } else {
            status.textContent = 'Complete';
            status.style.color = '#16a34a';
            resultDiv.innerHTML = renderTpLinkResult(data);
        }
    } catch(e) {
        const url = TPLINK_API_URL + '/api/speedtest';
        status.textContent = 'Failed: ' + e.message;
        status.style.color = '#dc2626';
        resultDiv.innerHTML = `<p style="font-size:0.8rem;color:#dc2626;margin-top:0.5rem">
            <strong>POST</strong> ${esc(url)}<br>
            <strong>Body:</strong> {"device_ip": "${esc(radioIp)}"}<br>
            <span style="color:#666">Likely causes: CORS blocked, server unreachable, or network error. Check browser console (F12) for details.</span>
        </p>`;
    }
}

async function fetchTpLinkHistory(radioIp) {
    const histBtn = document.getElementById('tplinkHistoryBtn');
    const resultDiv = document.getElementById('tplinkSpeedTestResult');

    histBtn.disabled = true;
    histBtn.textContent = 'Loading...';

    try {
        const url = TPLINK_API_URL + `/api/speedtest/${encodeURIComponent(radioIp)}?limit=50`;
        const resp = await fetch(url);

        if (!resp.ok) {
            const text = await resp.text().catch(() => '');
            resultDiv.innerHTML = `<p style="color:#dc2626;font-size:0.85rem">HTTP ${resp.status}: ${esc(text || resp.statusText)}</p>
                <p style="font-size:0.8rem;color:#666">GET ${esc(url)}</p>`;
            histBtn.disabled = false;
            histBtn.textContent = 'TP-Link Speed Test History';
            return;
        }

        const data = await resp.json();

        if (data.error) {
            resultDiv.innerHTML = `<p style="color:#dc2626;font-size:0.85rem">${esc(data.error)}</p>`;
        } else {
            const results = Array.isArray(data) ? data : (data.results || data.history || []);
            if (results.length === 0) {
                resultDiv.innerHTML = '<p style="color:#666;font-size:0.85rem">No TP-Link speed test history</p>';
            } else {
                let html = `<table style="width:100%;border-collapse:collapse;font-size:0.85rem;margin-top:0.5rem">
                    <thead><tr style="background:#f1f5f9;text-align:left">
                        <th style="padding:6px 8px">Download</th>
                        <th style="padding:6px 8px">Upload</th>
                        <th style="padding:6px 8px">Ping</th>
                        <th style="padding:6px 8px">When</th>
                    </tr></thead><tbody>`;
                results.forEach((r, i) => {
                    const dl = r.download_mbps !== undefined ? r.download_mbps + ' Mbps' : (r.download ? r.download : '-');
                    const ul = r.upload_mbps !== undefined ? r.upload_mbps + ' Mbps' : (r.upload ? r.upload : '-');
                    const ping = r.ping_ms !== undefined ? r.ping_ms + ' ms' : (r.ping ? r.ping : '-');
                    const when = r.timestamp || r.created_at || r.date || '-';
                    const bold = i === 0 ? 'font-weight:600;' : '';
                    html += `<tr style="border-bottom:1px solid #e5e7eb;${bold}">
                        <td style="padding:5px 8px">${esc(dl)}</td>
                        <td style="padding:5px 8px">${esc(ul)}</td>
                        <td style="padding:5px 8px">${esc(ping)}</td>
                        <td style="padding:5px 8px">${esc(when)}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
                resultDiv.innerHTML = html;
            }
        }
    } catch(e) {
        const url = TPLINK_API_URL + `/api/speedtest/${encodeURIComponent(radioIp)}?limit=50`;
        resultDiv.innerHTML = `<p style="color:#dc2626;font-size:0.85rem">Failed: ${esc(e.message)}</p>
            <p style="font-size:0.8rem;color:#666">
                <strong>GET</strong> ${esc(url)}<br>
                <span>Likely causes: CORS blocked, server unreachable, or network error. Check browser console (F12) for details.</span>
            </p>`;
    }

    histBtn.disabled = false;
    histBtn.textContent = 'TP-Link Speed Test History';
}

function renderTpLinkResult(data) {
    const dl = data.download_mbps !== undefined ? data.download_mbps + ' Mbps' : (data.download || '-');
    const ul = data.upload_mbps !== undefined ? data.upload_mbps + ' Mbps' : (data.upload || '-');
    const ping = data.ping_ms !== undefined ? data.ping_ms + ' ms' : (data.ping || '-');
    const server = data.server || data.server_name || '';
    return `<div class="grid" style="margin-top:0.5rem">
        ${gi('Download', dl, 'val-good')}
        ${gi('Upload', ul, 'val-good')}
        ${gi('Ping', ping)}
        ${server ? gi('Server', server) : ''}
    </div>`;
}
</script>
</body>
</html>
