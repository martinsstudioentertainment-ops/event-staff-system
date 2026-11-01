/**
 * AVIVA Match – Event Staff — bound Apps Script for the NEW form (owner olabodeoluwafemi2580@gmail.com).
 * Setup: Project Settings → Script properties: INTAKE_KEY = value of setting aviva_match_intake_key.
 * Run installTrigger() once (authorize in Chrome).
 * Each submit is checked by Olasentra (registered email OR mobile, no duplicates, cap 70).
 * Rejected responses are deleted from the form so they never count or sit in the sheet;
 * an email tells the person why (a Google Form cannot show a custom per-person message after submit).
 * At 70 the form is closed with the message "Event full".
 */
const ENDPOINT = 'https://register.olasentra.com/api/aviva-match-intake.php';
const FULL_MSG = 'Event full';
const UNREG_MSG = 'Please register on olasentra.com first, then come back to this form.';

function answers_(response) {
  var out = {};
  response.getItemResponses().forEach(function (ir) {
    out[ir.getItem().getTitle().toLowerCase()] = String(ir.getResponse() || '').trim();
  });
  return out;
}

function pick_(a, keys) {
  for (var k in a) { for (var i = 0; i < keys.length; i++) { if (k.indexOf(keys[i]) !== -1 && a[k]) return a[k]; } }
  return '';
}

function onFormSubmit(e) {
  var form = FormApp.getActiveForm();
  var r = e.response;
  var a = answers_(r);
  var email = r.getRespondentEmail() || pick_(a, ['email']);
  var payload = {
    key: PropertiesService.getScriptProperties().getProperty('INTAKE_KEY'),
    email: email,
    mobile: pick_(a, ['mobile', 'phone']),
    response_id: r.getId()
  };
  var res = UrlFetchApp.fetch(ENDPOINT, {
    method: 'post', contentType: 'application/json', payload: JSON.stringify(payload), muteHttpExceptions: true
  });
  var body = {};
  try { body = JSON.parse(res.getContentText()); } catch (err) { body = { ok: false, code: 'error' }; }

  if (!body.ok) {
    if (body.code === 'error' || res.getResponseCode() >= 500) { return; } // keep response; admin re-checks
    form.deleteResponse(r.getId()); // not counted, not in the sheet
    if (email) {
      var msg = body.code === 'not_registered' ? UNREG_MSG : (body.message || 'Your sign-up was not accepted.');
      MailApp.sendEmail(email, 'AVIVA Match – Event Staff: not accepted', msg);
    }
  }
  if (body.full || body.code === 'full') { closeForm_(form); }
}

function closeForm_(form) {
  form.setAcceptingResponses(false);
  form.setCustomClosedFormMessage(FULL_MSG);
}

function installTrigger() {
  var form = FormApp.getActiveForm();
  ScriptApp.getProjectTriggers().forEach(function (t) {
    if (t.getHandlerFunction() === 'onFormSubmit') ScriptApp.deleteTrigger(t);
  });
  ScriptApp.newTrigger('onFormSubmit').forForm(form).onFormSubmit().create();
  form.setCollectEmail(true);
  form.setLimitOneResponsePerUser(true); // extra duplicate guard (requires Google sign-in, same as last event)
  form.setConfirmationMessage('Thanks — Olasentra is checking your registration. If you are not registered you will get an email asking you to register on olasentra.com first.');
}
