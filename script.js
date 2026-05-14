// Navbar scroll effect
const navbar = document.getElementById('navbar');
window.addEventListener('scroll', () => {
    if (window.scrollY > 20) {
        navbar.classList.add('scrolled');
    } else {
        navbar.classList.remove('scrolled');
    }
});

// Mobile menu toggle
const hamburger = document.getElementById('hamburger');
const navMenu = document.getElementById('navMenu');

hamburger.addEventListener('click', () => {
    hamburger.classList.toggle('active');
    navMenu.classList.toggle('active');
});

// Close mobile menu when clicking a link
document.querySelectorAll('.nav-menu a').forEach(link => {
    link.addEventListener('click', () => {
        hamburger.classList.remove('active');
        navMenu.classList.remove('active');
    });
});

// Glossary: desktop = always expanded, mobile = accordion (one open at a time)
const glossaryItems = document.querySelectorAll('.glossary-item');
const glossaryDesktopMQ = window.matchMedia('(min-width: 721px)');

function syncGlossaryState() {
    if (glossaryDesktopMQ.matches) {
        glossaryItems.forEach(item => { item.open = true; });
    } else {
        glossaryItems.forEach(item => { item.open = false; });
    }
}

glossaryItems.forEach(item => {
    item.addEventListener('toggle', () => {
        if (glossaryDesktopMQ.matches) {
            if (!item.open) item.open = true;
            return;
        }
        if (item.open) {
            glossaryItems.forEach(other => {
                if (other !== item) other.open = false;
            });
        }
    });
});

syncGlossaryState();
glossaryDesktopMQ.addEventListener('change', syncGlossaryState);

// Reveal on scroll animation
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.style.opacity = '1';
            entry.target.style.transform = 'translateY(0)';
        }
    });
}, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

document.querySelectorAll('.service-card, .step-item, .case-card, .testimonial, .price-card, .glossary-item').forEach(el => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(30px)';
    el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
    observer.observe(el);
});

// Contact form: AJAX submission do /api/send (Node.js + nodemailer na Vercel)
const contactForm = document.getElementById('contactForm');
if (contactForm) {
    const statusEl = document.getElementById('formStatus');
    const submitBtn = document.getElementById('contactSubmit');

    contactForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (contactForm._honey && contactForm._honey.value) return;

        const originalLabel = submitBtn.textContent;
        submitBtn.disabled = true;
        submitBtn.textContent = 'Wysyłanie...';
        statusEl.className = 'form-status';
        statusEl.textContent = '';

        try {
            const response = await fetch(contactForm.action, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams(new FormData(contactForm))
            });
            const data = await response.json().catch(() => ({}));

            if (response.ok && data.success) {
                statusEl.classList.add('success');
                statusEl.textContent = '✅ Dziękuję! Wiadomość została wysłana – odezwę się wkrótce.';
                contactForm.reset();
            } else {
                throw new Error(data.message || 'Błąd wysyłki');
            }
        } catch (err) {
            statusEl.classList.add('error');
            statusEl.textContent = '❌ Nie udało się wysłać wiadomości. Napisz na WhatsApp +49 155 10247160 lub spróbuj ponownie.';
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = originalLabel;
        }
    });
}
