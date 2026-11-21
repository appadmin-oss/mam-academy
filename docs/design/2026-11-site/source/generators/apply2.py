from kit import *
INP = "width: 100%; box-sizing: border-box; min-height: 56px; padding: 0 18px; border-radius: 12px; border: 1.5px solid #CFC3B3; background: #FFFFFF; font: inherit; font-size: 17px; color: #211A13"
def field(id_, label, typ='text', auto='off', hint=''):
    h = f'<span style="display: block; font-size: 14px; color: #574B40; margin-top: 2px">{hint}</span>' if hint else ''
    return f'''<div style="display: flex; flex-direction: column; gap: 8px"><label for="{id_}" style="font-weight: 600">{label}{h}</label><span id="{id_}-err" style="font-size: 15px; font-weight: 600; color: #B0361C">{{{{ err.{id_} }}}}</span><input id="{id_}" type="{typ}" autocomplete="{auto}" value="{{{{ f.{id_} }}}}" onInput="{{{{ set.{id_} }}}}" aria-invalid="{{{{ inv.{id_} }}}}" aria-describedby="{id_}-err" style="{INP}; {{{{ bord.{id_} }}}}"></div>'''
def tiles(list_, label, cols='minmax(200px, 1fr)', n=4):
    return f'''<div role="radiogroup" aria-label="{label}" aria-describedby="tile-err" style="display: grid; grid-template-columns: repeat(auto-fill, {cols}); gap: 10px">
                <sc-for list="{{{{ {list_} }}}}" as="o" hint-placeholder-count="{n}"><button type="button" role="radio" aria-checked="{{{{ o.checked }}}}" onClick="{{{{ o.pick }}}}" class="tile" style="{{{{ o.style }}}}"><span style="display: flex; justify-content: space-between; align-items: center; gap: 12px; width: 100%"><span style="{SER}; font-size: 24px; font-weight: 600; line-height: 1.1">{{{{ o.label }}}}</span><span aria-hidden="true" style="{{{{ o.dot }}}}"></span></span><sc-if value="{{{{ o.hasSub }}}}" hint-placeholder-val="{{{{ true }}}}"><span style="font-size: 14px; color: #574B40; text-align: left">{{{{ o.sub }}}}</span></sc-if><sc-if value="{{{{ o.hasPrice }}}}" hint-placeholder-val="{{{{ false }}}}"><span style="margin-top: 4px; font-size: 14px; font-weight: 600; color: #8A5208">{{{{ o.price }}}}</span></sc-if></button></sc-for>
              </div>
              <span id="tile-err" role="alert" style="font-size: 15px; font-weight: 600; color: #B0361C">{{{{ err.tile }}}}</span>'''
def step(key, cap, q, hint, inner):
    return f'''          <sc-if value="{{{{ on.{key} }}}}" hint-placeholder-val="{{{{ {'true' if key == 'who' else 'false'} }}}}">
            <div class="qin" style="display: flex; flex-direction: column; gap: 24px">
              <p style="margin: 0; font-size: 14px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #8A5208">{cap}</p>
              <h1 tabindex="-1" ref="{{{{ headRef }}}}" style="margin: -12px 0 0; {SER}; font-weight: 500; font-size: clamp(36px, 4vw, 56px); line-height: 1.05; outline: none">{q}</h1>
              {f'<p style="margin: -8px 0 0; font-size: 17px; color: #574B40">{hint}</p>' if hint else ''}
              {inner}
            </div>
          </sc-if>
'''
CONT = '''<div style="display: flex; align-items: center; gap: 18px; flex-wrap: wrap; margin-top: 8px"><button type="button" onClick="{{ next }}" class="btn" style="min-height: 56px; padding: 0 30px; border-radius: 999px; border: 0; background: #211A13; color: #FAF8F4; font: inherit; font-size: 17px; font-weight: 600; cursor: pointer">{{ contLabel }}</button><span class="hide-sm" style="font-size: 14px; color: #8C7F70">or press <kbd style="font: inherit; padding: 2px 8px; border-radius: 6px; border: 1px solid #D6CCBE; background: #FFFFFF">Enter ↵</kbd></span></div>'''

steps = ''.join([
 step('who', 'Getting started', 'Who is this application for?', 'You can apply for yourself, or as a parent or guardian.', tiles('whoOpts', 'Applying for', 'minmax(220px, 1fr)', 2)),
 step('name', '{{ capLearner }}', '{{ qName }}', '', f'<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px">{field("first", "First name", auto="given-name")}{field("last", "Last name", auto="family-name")}</div>'),
 step('age', '{{ capLearner }}', '{{ qAge }}', 'This helps us place {{ them }} in the right class.', tiles('ageOpts', 'Age group', 'minmax(150px, 1fr)', 4)),
 step('instrument', 'The course', '{{ qInstr }}', 'Not sure? Choose “Help me choose” and we will talk it through at the assessment.', tiles('instrOpts', 'Instrument', 'minmax(190px, 1fr)', 9)),
 step('prog', 'The course', 'Choose a programme', 'Prices are per term at the starting level. Your exact level is set by the assessment.', tiles('progOpts', 'Programme', 'minmax(250px, 1fr)', 5)),
 step('mode', 'The course', '{{ qMode }}', '', tiles('modeOpts', 'Mode', 'minmax(240px, 1fr)', 2)),
 step('contact', 'Contact', '{{ qContact }}', 'We will only use these to send your confirmation and book the assessment.', f'<div style="display: flex; flex-direction: column; gap: 18px"><sc-if value="{{{{ isChild }}}}" hint-placeholder-val="{{{{ false }}}}">{field("gname", "Your full name", auto="name", hint="Parent or guardian")}</sc-if>{field("email", "Email address", "email", "email")}<sc-if value="{{{{ hasSuggest }}}}" hint-placeholder-val="{{{{ false }}}}"><p role="status" style="margin: -8px 0 0; font-size: 15px">Did you mean <button type="button" onClick="{{{{ useSuggest }}}}" class="ul" style="padding: 0; border: 0; background: none; font: inherit; font-weight: 600; color: #8A5208; cursor: pointer">{{{{ suggest }}}}</button>?</p></sc-if>{field("phone", "Phone number", "tel", "tel", "Include your country code, like +234")}</div>'),
 step('review', 'Nearly done', 'Check your answers', '', '''<dl style="margin: 0; border-top: 1px solid #E3DACD">
                <sc-for list="{{ review }}" as="r" hint-placeholder-count="7"><div style="display: grid; grid-template-columns: minmax(110px, 1fr) 2fr auto; gap: 12px; align-items: baseline; padding: 16px 0; border-bottom: 1px solid #E3DACD"><dt style="font-size: 15px; color: #574B40">{{ r.k }}</dt><dd style="margin: 0; font-weight: 600; overflow-wrap: anywhere">{{ r.v }}</dd><dd style="margin: 0"><button type="button" onClick="{{ r.change }}" class="ul" style="min-height: 44px; padding: 0; border: 0; background: none; font: inherit; font-size: 15px; color: #8A5208; cursor: pointer">Change<span style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0)"> {{ r.k }}</span></button></dd></div></sc-for>
              </dl>'''),
 step('pay', 'Final step', 'Pay the $5 registration fee', 'Tuition is paid later, once your level and plan are confirmed.', tiles('payOpts', 'Payment method', 'minmax(220px, 1fr)', 2) + '''
              <label style="display: flex; gap: 14px; align-items: flex-start; font-size: 16px; cursor: pointer; padding: 18px; border-radius: 14px; background: #FFFFFF; border: 1.5px solid #E3DACD"><input type="checkbox" checked="{{ f.terms }}" onChange="{{ set.terms }}" aria-invalid="{{ inv.terms }}" aria-describedby="terms-err" style="width: 22px; height: 22px; margin: 1px 0 0; accent-color: #211A13; flex: none"><span>I agree to the <a href="#terms" style="color: #8A5208">terms</a> and <a href="#policies" style="color: #8A5208">class policies</a>, and the details are correct.<span id="terms-err" style="display: block; font-size: 15px; font-weight: 600; color: #B0361C">{{ err.terms }}</span></span></label>'''),
])

body = f'''  <div class="ap" style="min-height: 100vh; display: flex; background: #FAF8F4">
    <aside class="ap-aside" aria-label="Your application" style="flex: 0 0 clamp(340px, 32vw, 440px); position: sticky; top: 0; height: 100vh; box-sizing: border-box; overflow-y: auto; background: #211A13; color: #FCF8F3; padding: 28px clamp(28px, 3vw, 44px); display: flex; flex-direction: column; gap: 36px">
      <a href="Main.dc.html" style="display: inline-flex; align-items: center; min-height: 44px; color: #FCF8F3; text-decoration: none; {SER}; font-size: 24px; font-weight: 600">MAM Academy</a>
      <div style="display: flex; flex-direction: column; gap: 6px"><p style="margin: 0; font-size: 13px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase; color: #F2A93B">Application · {{{{ intake }}}}</p><p style="margin: 0; font-size: 15px; color: #D8CEC1">About 5 minutes. Your answers are saved as you go.</p></div>
      <ol aria-label="Application sections" style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column">
        <sc-for list="{{{{ sections }}}}" as="x" hint-placeholder-count="4"><li aria-current="{{{{ x.cur }}}}" style="display: flex; gap: 16px; padding: 16px 0; border-top: 1px solid rgba(252,248,243,.12)">
          <span aria-hidden="true" style="{{{{ x.dot }}}}">{{{{ x.mark }}}}</span>
          <div style="flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px"><span style="{{{{ x.titleSt }}}}">{{{{ x.t }}}}</span><sc-if value="{{{{ x.canEdit }}}}" hint-placeholder-val="{{{{ false }}}}"><button type="button" onClick="{{{{ x.edit }}}}" class="ul" style="min-height: 32px; padding: 0; border: 0; background: none; font: inherit; font-size: 14px; color: #F2A93B; cursor: pointer">Edit<span style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0)"> {{{{ x.t }}}}</span></button></sc-if></div>
            <span style="font-size: 14px; color: #B8AC9E; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">{{{{ x.sum }}}}</span>
          </div>
        </li></sc-for>
      </ol>
      <div style="padding: 20px 22px; border-radius: 16px; background: rgba(252,248,243,.06); display: flex; flex-direction: column; gap: 2px">
        <p style="margin: 0; font-size: 14px; color: #D8CEC1">Estimated tuition</p>
        <p aria-live="polite" style="margin: 0; {SER}; font-size: 40px; font-weight: 600; line-height: 1.15">{{{{ fee }}}}<span style="font-family: Inter, sans-serif; font-size: 15px; font-weight: 400; color: #D8CEC1"> {{{{ feeUnit }}}}</span></p>
        <p style="margin: 0; font-size: 13px; color: #D8CEC1">{{{{ feeNote }}}} Due today: $5 registration.</p>
      </div>
      <div style="margin-top: auto; display: flex; flex-direction: column; gap: 6px; font-size: 13px; color: #B8AC9E">
        <span style="display: flex; align-items: center; gap: 8px"><svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#9CC59A" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5L20 7"></path></svg>{{{{ savedText }}}}<sc-if value="{{{{ canReset }}}}" hint-placeholder-val="{{{{ false }}}}"> · <button type="button" onClick="{{{{ reset }}}}" class="ul" style="padding: 0; border: 0; background: none; font: inherit; color: #D8CEC1; cursor: pointer">Start over</button></sc-if></span>
        <span>Questions? <a href="mailto:academy@mamcentre.org" style="color: #FCF8F3">academy@mamcentre.org</a> · <a href="tel:+2347044446517" style="color: #FCF8F3">+234 704 444 6517</a></span>
      </div>
    </aside>

    <div style="flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column">
      <div aria-hidden="true" style="position: sticky; top: 0; z-index: 5; height: 3px; background: #ECE5DA"><div style="{{{{ barStyle }}}}"></div></div>
      <div style="{PAD}; height: 72px; display: flex; align-items: center; justify-content: space-between; gap: 16px">
        <div style="display: flex; align-items: center; gap: 16px">
          <a href="Main.dc.html" class="ap-mob" style="display: none; align-items: center; min-height: 44px; {SER}; font-size: 22px; font-weight: 600; color: #211A13; text-decoration: none">MAM</a>
          <sc-if value="{{{{ canBack }}}}" hint-placeholder-val="{{{{ false }}}}"><button type="button" onClick="{{{{ back }}}}" class="ul" style="display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 0; border: 0; background: none; font: inherit; font-size: 15px; color: #211A13; cursor: pointer"><span aria-hidden="true">←</span> Back</button></sc-if>
        </div>
        <div style="display: flex; align-items: center; gap: 20px; font-size: 14px; color: #574B40"><span aria-live="polite">{{{{ counter }}}}</span><a href="Main.dc.html" class="ul hide-sm" style="color: #574B40; text-decoration: none">Save and exit</a></div>
      </div>
      <div class="ap-mob" style="display: none; {PAD}; padding-bottom: 8px; font-size: 14px; color: #574B40; justify-content: space-between; gap: 12px"><span>{{{{ mobSummary }}}}</span><strong style="color: #211A13">{{{{ fee }}}}</strong></div>

      <main id="main" onKeyDown="{{{{ onKey }}}}" style="flex: 1; {PAD}; padding-top: clamp(32px, 7vh, 96px); padding-bottom: 96px">
        <div style="max-width: 640px; display: flex; flex-direction: column; gap: 28px">
          <sc-if value="{{{{ hasErrors }}}}" hint-placeholder-val="{{{{ false }}}}"><div role="alert" style="padding: 18px 20px; border-radius: 12px; border: 2px solid #B0361C; background: #FFFFFF"><p style="margin: 0; font-weight: 600; color: #B0361C">There is a problem</p><p style="margin: 4px 0 0; font-size: 15px">{{{{ errorText }}}}</p></div></sc-if>
{steps}
          <sc-if value="{{{{ notDone }}}}" hint-placeholder-val="{{{{ true }}}}">{CONT}</sc-if>

          <sc-if value="{{{{ on.done }}}}" hint-placeholder-val="{{{{ false }}}}">
            <div style="display: flex; flex-direction: column; gap: 28px">
              <div style="padding: 32px; border-radius: 22px; background: #211A13; color: #FCF8F3; display: flex; flex-direction: column; gap: 10px">
                <svg aria-hidden="true" width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#F2A93B" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M7.5 12.5l3 3 6-6.5"></path></svg>
                <h1 tabindex="-1" ref="{{{{ headRef }}}}" style="margin: 4px 0 0; {SER}; font-weight: 500; font-size: clamp(38px, 4vw, 56px); line-height: 1.05; outline: none">Application received</h1>
                <p style="margin: 0; font-size: 17px; color: #D8CEC1">Welcome to MAM, {{{{ firstName }}}}. Your reference is <strong style="color: #F2A93B; letter-spacing: .04em">{{{{ ref }}}}</strong>. A confirmation is on its way to {{{{ f.email }}}}.</p>
              </div>
              <h2 style="margin: 8px 0 0; {SER}; font-weight: 500; font-size: 34px">What happens next</h2>
              <ol style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column">
                <sc-for list="{{{{ nextSteps }}}}" as="n" hint-placeholder-count="3"><li style="display: flex; gap: 18px; padding: 18px 0; border-top: 1px solid #E3DACD"><span aria-hidden="true" style="flex: none; {SER}; font-size: 28px; color: #C27A12; width: 28px">{{{{ n.i }}}}</span><div><p style="margin: 0; font-weight: 600">{{{{ n.t }}}}</p><p style="margin: 2px 0 0; font-size: 15px; color: #574B40">{{{{ n.d }}}}</p></div></li></sc-for>
              </ol>
              <div style="display: flex; gap: 10px; flex-wrap: wrap">{pill('Auditer.dc.html', 'Book my assessment <span aria-hidden="true">→</span>', 'dark')}{pill('Main.dc.html', 'Back to home', 'line')}</div>
            </div>
          </sc-if>
        </div>
      </main>
    </div>
  </div>
'''

CSS = '''<style>
.tile{transition:border-color .2s ease,background-color .2s ease,box-shadow .3s var(--out),transform .15s var(--soft)}
.tile:hover{border-color:#8C7F70!important}
.tile:active{transform:scale(.99)}
.tile:focus-visible,.ap input:focus-visible{outline:3px solid #F2A93B;outline-offset:2px}
.qin{animation:qIn .7s var(--out) both}
@keyframes qIn{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:none}}
@media (max-width: 960px){.ap-aside{display:none!important}.ap-mob{display:flex!important}}
@media (prefers-reduced-motion: reduce){.qin{animation:none}}
</style>'''

ORDER = ['who', 'name', 'age', 'instrument', 'prog', 'mode', 'contact', 'review', 'pay', 'done']
JS = r"""<script type="text/x-dc" data-dc-script data-props='{"$preview":{"width":1440,"height":1000}}'>
const ORDER = %s;
const BASE = 120;
const PROGS = [
  { k: 'standard', l: 'Standard', d: 'Your main instrument, graded by level.', fee: (o) => BASE * (o ? 1.2 : 1), unit: 'a term' },
  { k: 'certified', l: 'Certified Musician', d: 'Instrument plus theory, aural and the certification track.', fee: (o) => (BASE + 120) * (o ? 1.2 : 1), unit: 'a term', pop: true },
  { k: 'grand', l: 'Grand Musician', d: 'Three disciplines together, 13%% saving.', fee: (o) => Math.round(3 * BASE * 0.87 * (o ? 1.2 : 1)), unit: 'a term' },
  { k: 'crash', l: 'Crash Programme', d: 'Six-week intensive in any discipline.', fee: () => 70, unit: 'flat' },
  { k: 'meta', l: 'The MetaMusic Order', d: 'Twelve weeks, post-diploma.', fee: () => 60, unit: 'flat' }
];
const INSTR = ['Piano & Keyboard', 'Voice & Choir', 'Violin & Strings', 'Guitar', 'Drums & Percussion', 'Wind & Brass', 'Music Theory & Ear', 'Performance', 'Help me choose'];
const AGES = [['4–7', 'Early years'], ['8–12', 'Children'], ['13–17', 'Teens'], ['18+', 'Adults']];
class Component extends DCLogic {
  constructor(props) { super(props); this.state = { step: 0, back2review: false, err: {}, ref: '', f: { who: '', first: '', last: '', age: '', instrument: '', prog: '', mode: '', gname: '', email: '', phone: '', pay: 'card', terms: false } }; this.h = null; }
  componentDidMount() { try { const raw = window.localStorage && localStorage.getItem('mam-apply-v1'); if (raw) { const d = JSON.parse(raw); if (d && d.f && d.step < ORDER.indexOf('done')) this.setState({ f: Object.assign({}, this.state.f, d.f, { terms: false }), step: d.step, restored: true, savedAt: d.at }); } } catch (e) {} }
  persist() { try { if (!window.localStorage) return; const at = Date.now(); if (ORDER[this.state.step] === 'done') localStorage.removeItem('mam-apply-v1'); else localStorage.setItem('mam-apply-v1', JSON.stringify({ f: this.state.f, step: this.state.step, at })); this.lastSave = at; } catch (e) {} }
  componentDidUpdate(pp, ps) { if (ps && (ps.f !== this.state.f || ps.step !== this.state.step)) this.persist(); if (ps && ps.step !== this.state.step) { if (this.h && this.h.focus) this.h.focus(); if (window.scrollTo) window.scrollTo(0, 0); } }
  set(k, v) { this.setState({ f: Object.assign({}, this.state.f, { [k]: v }), err: {} }); }
  validate() {
    const f = this.state.f, k = ORDER[this.state.step], e = {}, mail = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;
    const need = { who: 'Choose who the application is for', age: 'Choose an age group', instrument: 'Choose an instrument, or “Help me choose”', prog: 'Choose a programme', mode: 'Choose how to learn' };
    if (need[k] && !f[k]) e.tile = need[k];
    if (k === 'name') { if (!f.first.trim()) e.first = 'Enter a first name'; if (!f.last.trim()) e.last = 'Enter a last name'; }
    if (k === 'contact') {
      if (f.who === 'child' && !f.gname.trim()) e.gname = 'Enter your full name';
      if (!mail.test(f.email.trim())) e.email = 'Enter an email address like name@example.com';
      if ((f.phone.match(/\d/g) || []).length < 7) e.phone = 'Enter a phone number with at least 7 digits';
    }
    if (k === 'pay' && !f.terms) e.terms = 'Tick the box to agree to the terms and class policies';
    return e;
  }
  next() {
    const e = this.validate();
    if (Object.keys(e).length) { this.setState({ err: e }); return; }
    let s = this.state.step + 1;
    if (this.state.back2review && ORDER[this.state.step] !== 'review') s = ORDER.indexOf('review');
    const extra = ORDER[s] === 'done' ? { ref: 'MAM-' + String(new Date().getFullYear()).slice(2) + '-' + String(Math.floor(1000 + Math.random() * 9000)) } : {};
    this.setState(Object.assign({ step: s, err: {}, back2review: false }, extra));
  }
  renderVals() {
    const s = this.state, f = s.f, key = ORDER[s.step], child = f.who === 'child';
    const first = f.first.trim(), them = child ? (first || 'your child') : 'you';
    const online = f.mode === 'online', prog = PROGS.find((p) => p.k === f.prog);
    const flat = prog && prog.unit === 'flat';
    const tileStyle = (on) => 'display: flex; flex-direction: column; align-items: flex-start; gap: 6px; min-height: 88px; padding: 18px 20px; border-radius: 16px; text-align: left; font: inherit; color: #211A13; cursor: pointer; background: ' + (on ? '#FFFFFF' : 'rgba(255,255,255,.55)') + '; border: 1.5px solid ' + (on ? '#211A13' : '#E3DACD') + '; box-shadow: ' + (on ? '0 0 0 1px #211A13, 0 18px 40px -28px rgba(33,26,19,.5)' : 'none');
    const dot = (on) => 'flex: none; width: 20px; height: 20px; border-radius: 50%%; box-sizing: border-box; border: ' + (on ? '6px solid #211A13' : '1.5px solid #B9AC9B') + '; background: #FFFFFF; transition: border .2s ease';
    const opt = (field, val, label, sub, price) => { const on = f[field] === val; return { label, sub: sub || '', hasSub: !!sub, price: price || '', hasPrice: !!price, checked: on ? 'true' : 'false', style: tileStyle(on), dot: dot(on), pick: () => this.set(field, val) }; };
    const money = (n) => '$' + Math.round(n).toLocaleString('en-US');
    const fee = prog ? money(prog.fee(online && !flat)) : '—';
    const err = s.err, errKeys = Object.keys(err);
    const setters = {}; ['first', 'last', 'gname', 'email', 'phone'].forEach((k) => { setters[k] = (ev) => this.set(k, ev.target.value); });
    setters.terms = (ev) => this.set('terms', ev.target.checked);
    const inv = {}, bord = {}; ['first', 'last', 'gname', 'email', 'phone', 'terms'].forEach((k) => { inv[k] = err[k] ? 'true' : 'false'; bord[k] = err[k] ? 'border-color: #B0361C; border-width: 2px' : ''; });
    const on = {}; ORDER.forEach((k) => { on[k] = k === key; });
    const qN = ORDER.indexOf('contact') + 1, idx = s.step + 1;
    const progName = prog ? prog.l : '';
    const goto = (k) => () => this.setState({ step: ORDER.indexOf(k), back2review: true, err: {} });
    const learner = (first + ' ' + f.last.trim()).trim();
    const young = f.age === '4–7', rec = f.age ? (young ? 'standard' : 'certified') : '';
    const firstInstr = ['Voice & Choir', 'Piano & Keyboard', 'Drums & Percussion'];
    const DOM = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'icloud.com', 'live.com', 'ymail.com'];
    const lev = (x, y) => { const m = Array.from({ length: x.length + 1 }, (_, i) => [i]); for (let j = 1; j <= y.length; j++) m[0][j] = j; for (let i = 1; i <= x.length; i++) for (let j = 1; j <= y.length; j++) m[i][j] = Math.min(m[i - 1][j] + 1, m[i][j - 1] + 1, m[i - 1][j - 1] + (x[i - 1] === y[j - 1] ? 0 : 1)); return m[x.length][y.length]; };
    let suggest = ''; const em = f.email.trim().toLowerCase(), at = em.lastIndexOf('@');
    if (at > 0) { const dom = em.slice(at + 1); if (dom && DOM.indexOf(dom) < 0) { const best = DOM.map((d) => [d, lev(dom, d)]).sort((p, q) => p[1] - q[1])[0]; if (best[1] > 0 && best[1] <= 2) suggest = em.slice(0, at + 1) + best[0]; } }
    const SEC = [['About the learner', ['who', 'name', 'age'], [child ? 'For my child' : (f.who ? 'For myself' : ''), learner, f.age]], ['The course', ['instrument', 'prog', 'mode'], [f.instrument, progName, f.mode ? (online ? 'Online' : 'In person') : '']], ['Contact', ['contact'], [f.email, f.phone]], ['Review and pay', ['review', 'pay'], []]];
    const curSec = SEC.findIndex((x) => x[1].indexOf(key) >= 0);
    const ago = s.savedAt || this.lastSave;
    return {
      intake: '[INTAKE]',
      sections: SEC.map(([t, keys, vals], i) => { const done = key === 'done' || i < curSec, cur = i === curSec;
        return { t, cur: cur ? 'step' : 'false', canEdit: done && key !== 'done' && i < 3, edit: goto(keys[0]),
          sum: done ? (vals.filter(Boolean).join(' · ') || 'Done') : (cur ? (key === 'review' ? 'Check your answers' : key === 'pay' ? 'Registration fee' : 'In progress') : ''),
          mark: done ? '✓' : String(i + 1),
          dot: 'flex: none; width: 28px; height: 28px; border-radius: 50%%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; ' + (done ? 'background: #F2A93B; color: #211A13' : cur ? 'border: 1.5px solid #F2A93B; color: #F2A93B' : 'border: 1px solid rgba(252,248,243,.25); color: rgba(252,248,243,.5)'),
          titleSt: 'font-weight: 600; font-size: 16px; color: ' + (cur || done ? '#FCF8F3' : 'rgba(252,248,243,.5)') }; }),
      savedText: (s.restored ? 'Welcome back — we kept your answers' : 'Progress saved on this device'), canReset: !!(f.who || f.first) && key !== 'done',
      reset: () => { try { localStorage.removeItem('mam-apply-v1'); } catch (e) {} this.setState({ step: 0, restored: false, err: {}, back2review: false, f: { who: '', first: '', last: '', age: '', instrument: '', prog: '', mode: '', gname: '', email: '', phone: '', pay: 'card', terms: false } }); },
      hasSuggest: !!suggest && key === 'contact', suggest, useSuggest: () => this.set('email', suggest),
      f, err, inv, bord, set: setters, on,
      headRef: (el) => { this.h = el; },
      isChild: child, them, firstName: first || 'friend', ref: s.ref,
      capLearner: child ? 'About your child' : 'About you',
      qName: child ? 'What is your child’s name?' : 'What is your name?',
      qAge: child ? 'How old is ' + them + '?' : 'How old are you?',
      qInstr: child ? 'Which instrument would ' + them + ' like to learn?' : 'Which instrument would you like to learn?',
      qMode: child ? 'How would ' + them + ' like to learn?' : 'How would you like to learn?',
      qContact: child ? 'Your contact details' : 'How can we reach you?',
      whoOpts: [opt('who', 'self', 'Myself', 'I am the learner'), opt('who', 'child', 'My child', 'I am a parent or guardian')],
      ageOpts: AGES.map(([a, d]) => opt('age', a, a, d)),
      instrOpts: INSTR.map((i) => opt('instrument', i, i, young && firstInstr.indexOf(i) >= 0 ? 'Great first instrument' : '')),
      progOpts: PROGS.map((p) => opt('prog', p.k, p.l, p.d, (p.unit === 'flat' ? money(p.fee(false)) + ' flat' : 'from ' + money(p.fee(false)) + ' a term') + (rec === p.k ? ' · Recommended for ' + f.age : (!rec && p.pop ? ' · Most chosen' : '')))),
      modeOpts: [opt('mode', 'onsite', 'In person', young ? 'Best for early years — at our studios.' : 'At our studios, in small groups.'), opt('mode', 'online', 'Online', flat ? 'Live classes. Same price.' : 'Live classes, anywhere. +20%%')],
      payOpts: [opt('pay', 'card', 'Card', 'Visa, Mastercard, Verve — via Paystack'), opt('pay', 'transfer', 'Bank transfer', 'Details sent by email')],
      review: [
        { k: 'Applying for', v: child ? 'My child' : 'Myself', change: goto('who') },
        { k: 'Name', v: learner || '—', change: goto('name') },
        { k: 'Age group', v: f.age || '—', change: goto('age') },
        { k: 'Instrument', v: f.instrument || '—', change: goto('instrument') },
        { k: 'Programme', v: progName || '—', change: goto('prog') },
        { k: 'Mode', v: f.mode ? (online ? 'Online' : 'In person') : '—', change: goto('mode') },
        { k: 'Contact', v: [child ? f.gname : '', f.email, f.phone].filter(Boolean).join(' · ') || '—', change: goto('contact') }
      ],
      summary: [['Learner', learner], ['Instrument', f.instrument], ['Programme', progName], ['Mode', f.mode ? (online ? 'Online' : 'In person') : '']].map(([k, v]) => ({ k, v: v || 'Not yet chosen', st: v ? '' : 'color: rgba(252,248,243,.45); font-weight: 400' })),
      asideTitle: learner ? learner : 'Start your musical journey',
      fee, feeUnit: prog ? prog.unit : '', feeNote: prog ? (flat ? 'One-off fee.' : 'Starting level; final after the assessment.') : 'Choose a programme to see the fee.',
      mobSummary: progName ? progName + (f.mode ? ' · ' + (online ? 'Online' : 'In person') : '') : 'Your application',
      counter: s.step < qN ? 'Question ' + idx + ' of ' + qN : (key === 'review' ? 'Check answers' : key === 'pay' ? 'Final step' : 'Complete'),
      barStyle: 'height: 100%%; background: #F2A93B; transition: width .8s cubic-bezier(.19,1,.22,1); width: ' + Math.round(s.step / (ORDER.length - 1) * 100) + '%%',
      canBack: s.step > 0 && key !== 'done',
      back: () => this.setState({ step: Math.max(0, s.step - 1), err: {}, back2review: false }),
      next: () => this.next(),
      onKey: (e) => { if (e.key === 'Enter' && e.target && e.target.tagName !== 'BUTTON' && e.target.tagName !== 'A') { e.preventDefault(); this.next(); } },
      contLabel: key === 'review' ? 'Confirm and continue' : key === 'pay' ? 'Pay $5 and submit' : s.back2review ? 'Save and return' : 'Continue',
      notDone: key !== 'done',
      hasErrors: errKeys.length > 0, errorText: errKeys.map((k) => err[k]).join('. '),
      nextSteps: [
        { i: '1', t: 'Check your email', d: 'Your confirmation and receipt arrive within a few minutes.' },
        { i: '2', t: 'Book your MAM Auditer assessment', d: 'About 30 minutes, online or in person. It sets ' + (child ? them + '’s' : 'your') + ' level.' },
        { i: '3', t: 'Meet your instructor', d: 'You receive a plan, a timetable and your first lesson date.' }
      ]
    };
  }
}
</script>""" % repr(ORDER)

html = f"""<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MAM Academy — Apply</title>
<script src="./support.js"></script>
</head>
<body>
<x-dc>
<helmet>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&amp;family=Inter:wght@400;500;600&amp;display=swap" rel="stylesheet">
{STYLE}
{CSS}
</helmet>
<div style="font-family: Inter, system-ui, sans-serif; color: #211A13; background: #FAF8F4; font-size: 16px; line-height: 1.6">
{body}</div>
</x-dc>
{JS}
</body>
</html>
"""
open('/home/claude/mam/project/Apply.dc.html', 'w').write(html)
print('ok')
