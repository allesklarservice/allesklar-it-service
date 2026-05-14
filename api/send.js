const nodemailer = require('nodemailer');

module.exports = async (req, res) => {
    res.setHeader('Content-Type', 'application/json; charset=utf-8');

    if (req.method !== 'POST') {
        return res.status(405).json({ success: false, message: 'Metoda niedozwolona' });
    }

    const body = req.body || {};

    if (body._honey) {
        return res.json({ success: true });
    }

    const name = (body.name || '').trim();
    const contact = (body.contact || '').trim();
    const topic = (body.topic || '').trim();
    const message = (body.message || '').trim();

    if (!name || !contact || !topic) {
        const missing = [];
        if (!name) missing.push('name');
        if (!contact) missing.push('contact');
        if (!topic) missing.push('topic');
        return res.status(400).json({
            success: false,
            message: 'Uzupełnij wymagane pola.',
            debug: { missing },
        });
    }

    if (name.length > 200 || contact.length > 200 || topic.length > 200 || message.length > 5000) {
        return res.status(400).json({ success: false, message: 'Zbyt długa zawartość pola.' });
    }

    for (const v of [name, contact, topic]) {
        if (/[\r\n]/.test(v)) {
            return res.status(400).json({ success: false, message: 'Nieprawidłowe dane.' });
        }
    }

    const cfg = {
        host: process.env.SMTP_HOST,
        port: parseInt(process.env.SMTP_PORT || '465', 10),
        secure: (process.env.SMTP_SECURE || 'ssl') === 'ssl',
        user: process.env.SMTP_USER,
        pass: process.env.SMTP_PASS,
        from: process.env.SMTP_FROM,
        fromName: process.env.SMTP_FROM_NAME || 'AllesKlar IT Service',
        to: process.env.SMTP_TO,
    };

    if (!cfg.host || !cfg.user || !cfg.pass || !cfg.from || !cfg.to) {
        console.error('[api/send] Brak konfiguracji SMTP w Env Variables');
        return res.status(500).json({ success: false, message: 'Konfiguracja serwera nie jest gotowa.' });
    }

    const isEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contact);
    const replyTo = isEmail ? contact : cfg.from;

    const ip = (req.headers['x-forwarded-for'] || '').split(',')[0].trim() || req.socket?.remoteAddress || 'n/d';
    const sentAt = new Date().toISOString().replace('T', ' ').substring(0, 19);

    const textBody = [
        'Nowa wiadomość z formularza kontaktowego AllesKlarService',
        '------------------------------------------------------------',
        '',
        `Imię i nazwisko:    ${name}`,
        `Telefon lub e-mail: ${contact}`,
        `Temat:              ${topic}`,
        '',
        'Wiadomość:',
        message,
        '',
        '------------------------------------------------------------',
        `Wysłano: ${sentAt}`,
        `IP: ${ip}`,
    ].join('\r\n');

    try {
        const transporter = nodemailer.createTransport({
            host: cfg.host,
            port: cfg.port,
            secure: cfg.secure,
            auth: { user: cfg.user, pass: cfg.pass },
        });

        await transporter.sendMail({
            from: { name: cfg.fromName, address: cfg.from },
            to: cfg.to,
            replyTo: replyTo,
            subject: `Nowa wiadomość ze strony AllesKlarService — ${topic}`,
            text: textBody,
        });

        return res.json({ success: true, message: 'Wiadomość wysłana' });
    } catch (err) {
        console.error('[api/send] SMTP error:', err && err.message);
        return res.status(500).json({
            success: false,
            message: 'Nie udało się wysłać wiadomości.',
            debug: err && err.message,
        });
    }
};
