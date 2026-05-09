// ============================================================
//  SMART SHIFT ASSISTANT — Express.js Backend API
//  Data source: config.json  (no database)
// ============================================================

const express = require('express');
const bcrypt  = require('bcrypt');
const jwt     = require('jsonwebtoken');
const admin   = require('firebase-admin');
const Anthropic = require('@anthropic-ai/sdk');
const fs      = require('fs');
const path    = require('path');

const app = express();
app.use(express.json());

// ── Load config ──────────────────────────────────────────────
const CONFIG_PATH = path.join(__dirname, 'config.json');

function loadConfig() {
  return JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
}

function saveConfig(cfg) {
  fs.writeFileSync(CONFIG_PATH, JSON.stringify(cfg, null, 2), 'utf8');
}

const ai = new Anthropic();

// ── Auth middleware ──────────────────────────────────────────
function auth(req, res, next) {
  const token = req.headers.authorization?.split(' ')[1];
  if (!token) return res.status(401).json({ error: 'Unauthorized' });
  try {
    req.user = jwt.verify(token, process.env.JWT_SECRET || 'shiftai-dev-secret');
    next();
  } catch {
    res.status(401).json({ error: 'Invalid token' });
  }
}

// ── Helper: find employee with zone data ─────────────────────
function getEmployeeWithZone(cfg, emp_num) {
  const emp  = cfg.employees.find(e => e.emp_num === emp_num && e.status === 'active');
  if (!emp) return null;
  const zone = cfg.zones.find(z => z.zone_id === emp.zone_id) || {};
  return { ...emp, zone_name: zone.zone_name, level: zone.level, site_name: zone.site_name };
}

// ============================================================
//  SCREEN 1 — LOGIN
//  POST /api/auth/login
// ============================================================
app.post('/api/auth/login', async (req, res) => {
  const { emp_num, pin } = req.body;
  const cfg = loadConfig();

  const emp = getEmployeeWithZone(cfg, emp_num);
  if (!emp) return res.status(401).json({ error: 'Employee not found' });

  // Plain PIN comparison (config stores plain PINs for demo; swap for bcrypt in production)
  const valid = pin === emp.pin;
  if (!valid) return res.status(401).json({ error: 'Invalid PIN' });

  const token = jwt.sign(
    { emp_num: emp.emp_num, role: emp.role, zone_id: emp.zone_id },
    process.env.JWT_SECRET || 'shiftai-dev-secret',
    { expiresIn: '12h' }
  );

  res.json({
    token,
    employee: {
      emp_num:  emp.emp_num,
      name:     emp.name + ' ' + emp.surname,
      role:     emp.role,
      zone:     emp.zone_name,
      level:    emp.level,
      site:     emp.site_name
    }
  });
});

// ============================================================
//  SCREEN 2 — DASHBOARD
//  GET /api/dashboard
// ============================================================
app.get('/api/dashboard', auth, async (req, res) => {
  const cfg    = loadConfig();
  const emp    = getEmployeeWithZone(cfg, req.user.emp_num);
  const zone   = req.user.zone_id;

  const hazards = cfg.hazards.filter(h => h.zone_id === zone && h.active);
  const notifs  = cfg.notifications
    .filter(n => n.emp_num === req.user.emp_num && !n.is_read)
    .slice(0, 5);

  const shiftTasks = cfg.tasks.filter(t => t.zone_id === zone);

  // AI shift briefing
  const hazardText = hazards.map(h => `${h.severity.toUpperCase()} — ${h.description}`).join('\n') || 'None';
  let ai_briefing = `Good shift, ${emp.name}. You have ${shiftTasks.length} tasks today.`;

  try {
    const aiMsg = await ai.messages.create({
      model: 'claude-sonnet-4-6',
      max_tokens: 200,
      messages: [{
        role: 'user',
        content: `You are a mining shift AI. Give a 2-sentence shift briefing for ${emp.name}.
They have ${shiftTasks.length} tasks today. Active hazards:\n${hazardText}
Be concise and safety-focused.`
      }]
    });
    ai_briefing = aiMsg.content[0].text;
  } catch (e) {
    // Keep the default briefing if AI is unavailable
  }

  res.json({
    dashboard: {
      emp_num:         emp.emp_num,
      name:            emp.name,
      zone:            emp.zone_name,
      total_tasks:     shiftTasks.length,
      completed_tasks: 0
    },
    hazards,
    notifications: notifs,
    ai_briefing
  });
});

// ============================================================
//  SCREEN 3 — TASKS
//  GET  /api/shifts/:zone/tasks
//  POST /api/tasks/:taskId/log
// ============================================================
app.get('/api/shifts/:zone/tasks', auth, (req, res) => {
  const cfg   = loadConfig();
  const tasks = cfg.tasks.filter(t => t.zone_id === req.params.zone);
  res.json(tasks);
});

app.post('/api/tasks/:taskId/log', auth, async (req, res) => {
  const { status, raw_input, input_type } = req.body;
  let ai_summary = null;

  if (raw_input) {
    try {
      const aiRes = await ai.messages.create({
        model: 'claude-sonnet-4-6',
        max_tokens: 150,
        messages: [{
          role: 'user',
          content: `Convert this mining worker log into a structured 2-sentence report.
Input: "${raw_input}".
Format: [Action taken]. [Status and any issues noted].`
        }]
      });
      ai_summary = aiRes.content[0].text;
    } catch (e) {
      ai_summary = raw_input;
    }
  }

  res.json({
    task_id:    req.params.taskId,
    emp_num:    req.user.emp_num,
    status,
    raw_input,
    ai_summary,
    input_type: input_type || 'text',
    logged_at:  new Date().toISOString()
  });
});

// ============================================================
//  SCREEN 4 — AI CHAT
//  POST /api/chat
// ============================================================
app.post('/api/chat', auth, async (req, res) => {
  const { message } = req.body;
  const cfg  = loadConfig();
  const zone = req.user.zone_id;

  const hazards = cfg.hazards.filter(h => h.zone_id === zone && h.active);
  const tasks   = cfg.tasks.filter(t => t.zone_id === zone);
  const docs    = cfg.safety_documents.filter(d => d.is_active);

  const context = `
Worker: ${req.user.emp_num} | Role: ${req.user.role}
Active hazards: ${JSON.stringify(hazards)}
Today's tasks: ${JSON.stringify(tasks)}
Safety docs available: ${docs.map(d => d.title).join(', ')}`;

  try {
    const aiRes = await ai.messages.create({
      model: 'claude-sonnet-4-6',
      max_tokens: 300,
      system: `You are a mining shift AI assistant. Be concise, safety-first. Context:\n${context}`,
      messages: [{ role: 'user', content: message }]
    });
    res.json({ reply: aiRes.content[0].text });
  } catch (e) {
    res.json({ reply: 'AI assistant is currently unavailable. Please check with your supervisor.' });
  }
});

// ============================================================
//  SCREEN 5 — SHIFT REPORT
//  POST /api/reports/generate
//  POST /api/reports/submit
// ============================================================
app.post('/api/reports/generate', auth, async (req, res) => {
  const { work_done, issues, equipment_status, handover_notes, tasks_completed } = req.body;

  try {
    const aiRes = await ai.messages.create({
      model: 'claude-sonnet-4-6',
      max_tokens: 400,
      messages: [{
        role: 'user',
        content: `Generate a formal mining shift report.
Worker: ${req.user.emp_num} | Date: ${new Date().toLocaleDateString()}
Work done: ${work_done}
Issues: ${issues}
Equipment: ${equipment_status || 'Not reported'}
Handover: ${handover_notes}
Tasks completed: ${tasks_completed}
Format as a structured professional report with sections.`
      }]
    });
    res.json({ ai_report: aiRes.content[0].text });
  } catch (e) {
    res.json({ ai_report: `SHIFT REPORT\n============\nDate: ${new Date().toLocaleDateString()}\nWorker: ${req.user.emp_num}\n\nWORK PERFORMED\n${work_done}\n\nISSUES\n${issues}\n\nHANDOVER NOTES\n${handover_notes}` });
  }
});

app.post('/api/reports/submit', auth, (req, res) => {
  const { raw_input, ai_summary } = req.body;
  const cfg = loadConfig();

  // Append to audit log in config
  cfg.audit_log.unshift({
    time: new Date().toLocaleTimeString('en-ZA', { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
    type: 'success',
    text: 'Shift report submitted',
    user: `${req.user.emp_num}`
  });
  saveConfig(cfg);

  res.json({ submitted: true, emp_num: req.user.emp_num, submitted_at: new Date().toISOString() });
});

// ============================================================
//  DATA — HAZARDS
//  POST /api/hazards      — report a new hazard
//  PUT  /api/hazards/:id  — resolve a hazard
// ============================================================
app.post('/api/hazards', auth, (req, res) => {
  const { zone_id, type, severity, description, doc_id } = req.body;
  const cfg = loadConfig();

  const newHazard = {
    hazard_id:   'H' + Date.now(),
    zone_id,
    type,
    severity,
    description,
    reported_by: req.user.emp_num,
    reported_at: new Date().toISOString(),
    active:      true
  };

  cfg.hazards.push(newHazard);
  saveConfig(cfg);

  res.json(newHazard);
});

app.put('/api/hazards/:id/resolve', auth, (req, res) => {
  const cfg = loadConfig();
  const hazard = cfg.hazards.find(h => h.hazard_id === req.params.id);
  if (!hazard) return res.status(404).json({ error: 'Hazard not found' });
  hazard.active      = false;
  hazard.resolved_by = req.user.emp_num;
  hazard.resolved_at = new Date().toISOString();
  saveConfig(cfg);
  res.json(hazard);
});

// ============================================================
//  DATA — EMPLOYEES (Admin only)
//  GET    /api/employees
//  POST   /api/employees
//  PUT    /api/employees/:id
//  DELETE /api/employees/:id
// ============================================================
app.get('/api/employees', auth, (req, res) => {
  const cfg = loadConfig();
  const safe = cfg.employees.map(({ pin, ...rest }) => rest);
  res.json(safe);
});

app.post('/api/employees', auth, (req, res) => {
  if (req.user.role !== 'Admin') return res.status(403).json({ error: 'Admin only' });
  const cfg = loadConfig();
  const emp = { ...req.body, status: 'active' };
  cfg.employees.push(emp);
  saveConfig(cfg);
  const { pin, ...safe } = emp;
  res.json(safe);
});

app.put('/api/employees/:id', auth, (req, res) => {
  if (req.user.role !== 'Admin') return res.status(403).json({ error: 'Admin only' });
  const cfg = loadConfig();
  const idx = cfg.employees.findIndex(e => e.emp_num === req.params.id);
  if (idx === -1) return res.status(404).json({ error: 'Employee not found' });
  cfg.employees[idx] = { ...cfg.employees[idx], ...req.body };
  saveConfig(cfg);
  const { pin, ...safe } = cfg.employees[idx];
  res.json(safe);
});

app.delete('/api/employees/:id', auth, (req, res) => {
  if (req.user.role !== 'Admin') return res.status(403).json({ error: 'Admin only' });
  const cfg = loadConfig();
  const emp = cfg.employees.find(e => e.emp_num === req.params.id);
  if (!emp) return res.status(404).json({ error: 'Employee not found' });
  emp.status = 'inactive';
  saveConfig(cfg);
  res.json({ deactivated: true });
});

// ============================================================
//  DATA — SAFETY DOCUMENTS
//  GET  /api/documents
//  POST /api/documents
// ============================================================
app.get('/api/documents', auth, (req, res) => {
  const cfg = loadConfig();
  res.json(cfg.safety_documents.filter(d => d.is_active));
});

app.post('/api/documents', auth, (req, res) => {
  const { title, category, file_url, version } = req.body;
  const cfg = loadConfig();
  const doc = {
    doc_id:      'D' + Date.now(),
    title,
    category,
    file_url,
    version:     version || '1.0',
    uploaded_by: req.user.emp_num,
    uploaded_at: new Date().toISOString().split('T')[0],
    is_active:   true
  };
  cfg.safety_documents.push(doc);
  saveConfig(cfg);
  res.json(doc);
});

// ============================================================
//  DATA — CONFIG (Admin read/write for settings page)
//  GET  /api/config
//  PUT  /api/config
// ============================================================
app.get('/api/config', auth, (req, res) => {
  if (req.user.role !== 'Admin') return res.status(403).json({ error: 'Admin only' });
  const cfg = loadConfig();
  res.json({ site: cfg.site, shifts: cfg.shifts, thresholds: cfg.thresholds, integrations: cfg.integrations });
});

app.put('/api/config', auth, (req, res) => {
  if (req.user.role !== 'Admin') return res.status(403).json({ error: 'Admin only' });
  const cfg = loadConfig();
  if (req.body.site)         cfg.site         = { ...cfg.site,         ...req.body.site };
  if (req.body.shifts)       cfg.shifts       = { ...cfg.shifts,       ...req.body.shifts };
  if (req.body.thresholds)   cfg.thresholds   = { ...cfg.thresholds,   ...req.body.thresholds };
  if (req.body.integrations) cfg.integrations = { ...cfg.integrations, ...req.body.integrations };
  saveConfig(cfg);
  res.json({ saved: true });
});

// ============================================================
//  DATA — AUDIT LOG
//  GET /api/audit
// ============================================================
app.get('/api/audit', auth, (req, res) => {
  if (!['Admin', 'Supervisor'].includes(req.user.role)) return res.status(403).json({ error: 'Forbidden' });
  const cfg = loadConfig();
  res.json(cfg.audit_log);
});

// ============================================================
//  STATIC FILE SERVING
//  Serve HTML files for front-end access
// ============================================================
app.use(express.static(__dirname, {
  extensions: ['html', 'js', 'css'],
  setHeaders: (res, path) => {
    res.set('Cache-Control', 'public, max-age=3600');
  }
}));

// ============================================================
//  HEALTH CHECK ENDPOINT
//  GET /health — For Docker health checks
// ============================================================
app.get('/health', (req, res) => {
  res.status(200).json({
    status: 'ok',
    timestamp: new Date().toISOString(),
    uptime: process.uptime()
  });
});

// ============================================================
//  START SERVER
// ============================================================
const PORT = process.env.PORT || 3000;

app.listen(PORT, () => {
  console.log(`\n🚀 ShiftAI API running on port ${PORT}`);
  console.log(`📊 Dashboard: http://localhost:${PORT}`);
  console.log(`🔌 API Endpoints: http://localhost:${PORT}/api/*`);
  console.log(`💾 Data source: config.json\n`);
});
