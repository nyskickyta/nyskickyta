const revealElements = document.querySelectorAll(".reveal");

const observer = new IntersectionObserver(
  (entries) => {
    entries.forEach((entry, index) => {
      if (!entry.isIntersecting) return;

      entry.target.style.transitionDelay = `${index * 70}ms`;
      entry.target.classList.add("is-visible");
      observer.unobserve(entry.target);
    });
  },
  {
    threshold: 0.18,
  }
);

revealElements.forEach((element) => observer.observe(element));

const offerForm = document.querySelector(".contact-form");
const startedField = offerForm?.querySelector('input[name="form_started"]');
const formStatus = document.querySelector("[data-form-status]");
const navToggle = document.querySelector(".nav-toggle");
const siteNav = document.querySelector(".site-nav");
const siteHeader = document.querySelector(".site-header");
const quoteAnchorLinks = document.querySelectorAll('a[href="#offert-form"]');
const statusMessages = {
  validation: "Fyll i namn, telefon, valen i formuläret och adressuppgifterna innan du skickar formuläret.",
  review: "Din förfrågan kunde inte skickas direkt. Kontrollera uppgifterna och försök igen, eller ring oss så hjälper vi dig direkt.",
  error: "Något gick fel när formuläret skulle skickas. Försök igen om en liten stund eller ring oss direkt.",
};

function closeMobileNav() {
  if (!navToggle || !siteNav) {
    return;
  }

  navToggle.setAttribute("aria-expanded", "false");
  navToggle.setAttribute("aria-label", "Öppna meny");
  siteNav.classList.remove("is-open");
}

function scrollToOfferForm() {
  const target = document.querySelector("#offert-form");
  if (!target) {
    return;
  }

  const isMobileViewport = window.matchMedia("(max-width: 760px)").matches;
  const headerOffset = siteHeader ? siteHeader.getBoundingClientRect().height : 96;
  const extraOffset = isMobileViewport ? 56 : 20;
  const targetTop = target.getBoundingClientRect().top + window.scrollY - headerOffset;

  window.scrollTo({
    top: Math.max(targetTop - extraOffset, 0),
    behavior: "smooth",
  });
}

if (startedField) {
  startedField.value = String(Date.now());
}

if (formStatus) {
  const params = new URLSearchParams(window.location.search);
  const status = params.get("status");
  const message = status ? statusMessages[status] : "";

  if (message) {
    formStatus.hidden = false;
    formStatus.dataset.status = status;
    formStatus.textContent = message;
  }
}

if (navToggle && siteNav) {
  navToggle.addEventListener("click", () => {
    const isOpen = navToggle.getAttribute("aria-expanded") === "true";
    navToggle.setAttribute("aria-expanded", String(!isOpen));
    navToggle.setAttribute("aria-label", isOpen ? "Öppna meny" : "Stäng meny");
    siteNav.classList.toggle("is-open", !isOpen);
  });

  siteNav.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", () => {
      closeMobileNav();
    });
  });
}

quoteAnchorLinks.forEach((link) => {
  link.addEventListener("click", (event) => {
    event.preventDefault();
    closeMobileNav();
    window.setTimeout(scrollToOfferForm, 180);
  });
});
