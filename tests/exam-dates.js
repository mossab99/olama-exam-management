// Run from a WordPress installation: node tests/exam-dates.js
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const view = fs.readFileSync(path.join(__dirname, '../admin-views/exam-schedule.php'), 'utf8');
for (const [, script] of view.matchAll(/<script>([\s\S]*?)<\/script>/g)) {
    new vm.Script(script.replace(/<\?php[\s\S]*?\?>/g, 'null'));
}

// Use WordPress's actual jQuery UI date parser. Only its unused DOM initialization is stubbed.
const jquery = () => ({ on() { return this; } });
jquery.ui = {};
jquery.fn = {};
jquery.extend = (...args) => {
    if (typeof args[0] === 'boolean') args.shift();
    return Object.assign(...args);
};
const context = vm.createContext({ jQuery: jquery, $: jquery, alert() {} });
const wordpressRoot = path.resolve(__dirname, '../../../..');
vm.runInContext(fs.readFileSync(path.join(wordpressRoot, 'wp-includes/js/jquery/ui/datepicker.js'), 'utf8'), context);
const helpers = view.slice(view.indexOf('        function parseExamDate('), view.indexOf('        // This module initializes'));
const validate = view.slice(view.indexOf('        function validateDate('), view.indexOf("        $('#open-add-exam-modal')"));
vm.runInContext(helpers + validate.replace(/<\?php[\s\S]*?\?>/g, 'null'), context);
context.masterExams = [
    { id: '3', start_date: '2026-10-18', end_date: '2026-11-05' },
    { id: '4', start_date: '2026-12-01', end_date: '2026-12-15' }
];

const originalTimezone = process.env.TZ;
try {
    for (const timezone of ['UTC', 'Asia/Amman', 'America/Los_Angeles']) {
        process.env.TZ = timezone;
        for (const [display, iso, weekday] of [
            ['25-10-2026', '2026-10-25', 0],
            ['27-10-2026', '2026-10-27', 2],
            ['28-10-2026', '2026-10-28', 3],
            ['01-11-2026', '2026-11-01', 0],
            ['03-11-2026', '2026-11-03', 2]
        ]) {
            const date = context.parseExamDate(display);
            assert.equal(jquery.datepicker.formatDate('yy-mm-dd', date), iso, timezone);
            assert.equal(date.getDay(), weekday, timezone);
            assert.equal(context.parseExamDate(iso).getDay(), weekday, timezone);
        }
        for (const invalid of ['', '31-02-2026', '2026-02-29', '2026-13-01', '10/11/2026', '1-11-2026']) {
            assert.equal(context.parseExamDate(invalid), null, invalid);
            assert.equal(context.validateDate(invalid, 3), false, invalid);
        }
        assert.equal(context.validateDate('18-10-2026', 3), true);
        assert.equal(context.validateDate('05-11-2026', 3), true);
        assert.equal(context.validateDate('17-10-2026', 3), false);
        assert.equal(context.validateDate('06-11-2026', 3), false);
        assert.equal(context.validateDate('01-12-2026', 4), true, 'Use the selected exam period');
        assert.equal(context.validateDate('01-12-2026', 3), false);
    }
} finally {
    if (originalTimezone === undefined) delete process.env.TZ;
    else process.env.TZ = originalTimezone;
}
console.log('Exam date JavaScript checks passed (syntax, actual datepicker parsing, weekdays, selected periods, three timezones).');
