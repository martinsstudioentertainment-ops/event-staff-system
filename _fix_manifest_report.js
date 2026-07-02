const fs = require('fs');
const path = require('path');

const report = JSON.parse(fs.readFileSync('e:/event-staff-system/_transcript_extraction_report.json', 'utf8'));

// Fix AndroidManifest: use chronological order within c596347a session
const TRANSCRIPT = 'C:/Users/Workshop/.cursor/projects/e-event-staff-system/agent-transcripts/c596347a-a2e7-4e7f-b397-6aede9ac524a/c596347a-a2e7-4e7f-b397-6aede9ac524a.jsonl';
const lines = fs.readFileSync(TRANSCRIPT, 'utf8').split(/\r?\n/);

let manifest = null;
for (const [i, line] of lines.entries()) {
  try {
    const obj = JSON.parse(line);
    const content = obj?.message?.content;
    if (!Array.isArray(content)) continue;
    for (const tu of content) {
      if (tu?.type !== 'tool_use') continue;
      const input = tu.input || {};
      if (!String(input.path || '').includes('AndroidManifest.xml')) continue;
      if (tu.name === 'Write' && input.contents) {
        manifest = input.contents;
        console.log('Write at line', i + 1, 'len', manifest.length);
      } else if (tu.name === 'StrReplace' && manifest) {
        if (manifest.includes(input.old_string)) {
          manifest = manifest.replace(input.old_string, input.new_string);
          console.log('StrReplace at line', i + 1, 'len', manifest.length);
        }
      }
    }
  } catch (_) {}
}

// Update report
const idx = report.found.findIndex((f) => f.path === 'app/src/main/AndroidManifest.xml');
if (idx >= 0 && manifest) {
  report.found[idx].content = manifest;
  report.found[idx].content_len = manifest.length;
  report.found[idx].source = 'Write+StrReplace-chain (c596347a GPS manifest)';
  report.found[idx].transcript = TRANSCRIPT;
  report.found[idx].line = 519;
}

fs.writeFileSync('e:/event-staff-system/_transcript_extraction_report.json', JSON.stringify(report, null, 2));
fs.writeFileSync('e:/event-staff-system/_reconstructed_AndroidManifest.xml', manifest);
console.log('Updated manifest len:', manifest.length);

// Also emit per-file content files for recovery
const outDir = 'e:/event-staff-system/_phase3_recovery_contents';
fs.mkdirSync(outDir, { recursive: true });
for (const f of report.found) {
  const safe = f.path.replace(/[/\\]/g, '__');
  fs.writeFileSync(path.join(outDir, safe), f.content, 'utf8');
}
console.log('Wrote', report.found.length, 'files to', outDir);
