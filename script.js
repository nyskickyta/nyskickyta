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
const statusMessages = {
  validation: "Fyll i namn, telefon och en giltig e-postadress innan du skickar formuläret.",
  review: "Din förfrågan kunde inte skickas direkt. Kontrollera uppgifterna och försök igen, eller ring oss så hjälper vi dig direkt.",
  error: "Något gick fel när formuläret skulle skickas. Försök igen om en liten stund eller ring oss direkt.",
};

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
      navToggle.setAttribute("aria-expanded", "false");
      navToggle.setAttribute("aria-label", "Öppna meny");
      siteNav.classList.remove("is-open");
    });
  });
}
