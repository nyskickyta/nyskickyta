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
const addressStreetField = offerForm?.querySelector("[data-address-street]");
const addressAutocompleteShell = offerForm?.querySelector("[data-address-autocomplete-shell]");
const addressPostalCodeField = offerForm?.querySelector('[data-address-postcode]');
const addressCityField = offerForm?.querySelector("[data-address-city]");
const addressHelpText = offerForm?.querySelector(".form-help");
const formStatus = document.querySelector("[data-form-status]");
const navToggle = document.querySelector(".nav-toggle");
const siteNav = document.querySelector(".site-nav");
const siteHeader = document.querySelector(".site-header");
const quoteAnchorLinks = document.querySelectorAll('a[href="#offert-form"]');
const siteConfig = window.NYSKICK_SITE_CONFIG || {};
const isSafariBrowser = /^((?!chrome|android).)*safari/i.test(window.navigator.userAgent);
const analyticsMeasurementId = "G-9TKSWWGZF7";
const analyticsConsentKey = "nyskick_cookie_consent";
const thankYouTrackedKey = "nyskick_generate_lead_tracked";
const statusMessages = {
  validation: "Fyll i namn, telefon, valen i formuläret och adressuppgifterna innan du skickar formuläret.",
  review: "Din förfrågan kunde inte skickas direkt. Kontrollera uppgifterna och försök igen, eller ring oss så hjälper vi dig direkt.",
  error: "Något gick fel när formuläret skulle skickas. Försök igen om en liten stund eller ring oss direkt.",
};

function getAnalyticsConsent() {
  try {
    return window.localStorage.getItem(analyticsConsentKey);
  } catch (error) {
    return "";
  }
}

function setAnalyticsConsent(value) {
  try {
    window.localStorage.setItem(analyticsConsentKey, value);
  } catch (error) {
    return;
  }
}

function trackLeadIfNeeded() {
  if (typeof window.gtag !== "function") {
    return;
  }

  const isThankYouPage = /^\/tack(?:\/|(?:\.html)?)$/.test(window.location.pathname);
  if (!isThankYouPage) {
    return;
  }

  try {
    if (window.sessionStorage.getItem(thankYouTrackedKey) === "1") {
      return;
    }
  } catch (error) {
    // Ignore storage access issues and still attempt to track once per load.
  }

  window.gtag("event", "generate_lead", {
    event_category: "form",
    event_label: "quote_request",
  });

  try {
    window.sessionStorage.setItem(thankYouTrackedKey, "1");
  } catch (error) {
    // Ignore storage access issues.
  }
}

function loadAnalytics() {
  if (!analyticsMeasurementId || document.querySelector('script[data-ga-loader="1"]')) {
    trackLeadIfNeeded();
    return;
  }

  window.dataLayer = window.dataLayer || [];
  window.gtag = window.gtag || function gtag() {
    window.dataLayer.push(arguments);
  };

  window.gtag("js", new Date());
  window.gtag("config", analyticsMeasurementId);

  const script = document.createElement("script");
  script.async = true;
  script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(analyticsMeasurementId)}`;
  script.dataset.gaLoader = "1";
  script.addEventListener("load", () => {
    trackLeadIfNeeded();
  });
  document.head.appendChild(script);
}

function removeCookieBanner() {
  document.querySelector("[data-cookie-banner]")?.remove();
}

function createCookieBanner() {
  if (document.querySelector("[data-cookie-banner]")) {
    return;
  }

  const banner = document.createElement("div");
  banner.className = "cookie-banner";
  banner.dataset.cookieBanner = "1";
  banner.innerHTML = `
    <div class="cookie-banner__inner">
      <div class="cookie-banner__copy">
        <strong>Cookies för statistik</strong>
        <p>Vi använder statistikcookies för att förstå hur webbplatsen används och förbättra formulär och innehåll. Läs mer i vår <a href="${window.location.pathname.startsWith("/altantvatt/") || window.location.pathname.startsWith("/stentvatt/") ? "../integritetspolicy.html" : "integritetspolicy.html"}">integritetspolicy</a>.</p>
      </div>
      <div class="cookie-banner__actions">
        <button class="button button-secondary" type="button" data-cookie-decline>Endast nödvändiga</button>
        <button class="button button-primary" type="button" data-cookie-accept>Godkänn statistik</button>
      </div>
    </div>
  `;

  banner.querySelector("[data-cookie-accept]")?.addEventListener("click", () => {
    setAnalyticsConsent("accepted");
    removeCookieBanner();
    loadAnalytics();
  });

  banner.querySelector("[data-cookie-decline]")?.addEventListener("click", () => {
    setAnalyticsConsent("declined");
    removeCookieBanner();
  });

  document.body.appendChild(banner);
}

function initCookieConsent() {
  const consent = getAnalyticsConsent();

  if (consent === "accepted") {
    loadAnalytics();
    return;
  }

  if (consent === "declined") {
    return;
  }

  createCookieBanner();
}

function showFormStatus(message, status = "validation") {
  if (!formStatus) {
    return;
  }

  formStatus.hidden = false;
  formStatus.dataset.status = status;
  formStatus.textContent = message;
}

function encodeFormData(data) {
  return Object.keys(data)
    .map((key) => `${encodeURIComponent(key)}=${encodeURIComponent(data[key])}`)
    .join("&");
}

function getWidgetStreetValue(widget) {
  if (!widget) {
    return "";
  }

  const directValue = typeof widget.value === "string" ? widget.value.trim() : "";
  if (directValue) {
    return directValue;
  }

  const nestedInput = widget.querySelector("input");
  if (nestedInput && typeof nestedInput.value === "string") {
    return nestedInput.value.trim();
  }

  return "";
}

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

function formatSwedishPostcode(value) {
  const digits = String(value || "").replace(/\D+/g, "").slice(0, 5);

  if (digits.length <= 3) {
    return digits;
  }

  return `${digits.slice(0, 3)} ${digits.slice(3)}`;
}

function loadGoogleMapsPlaces(apiKey, countryCode) {
  return new Promise((resolve, reject) => {
    if (!apiKey) {
      reject(new Error("Missing Google Maps API key"));
      return;
    }

    if (window.google?.maps) {
      resolve(window.google.maps);
      return;
    }

    const existingScript = document.querySelector('script[data-google-maps-places="1"]');
    if (existingScript) {
      existingScript.addEventListener("load", () => resolve(window.google?.maps));
      existingScript.addEventListener("error", () => reject(new Error("Google Maps script failed to load")));
      return;
    }

    const script = document.createElement("script");
    const params = new URLSearchParams({
      key: apiKey,
      libraries: "places",
      language: "sv",
      region: String(countryCode || "se").toUpperCase(),
      loading: "async",
    });
    script.src = `https://maps.googleapis.com/maps/api/js?${params.toString()}`;
    script.async = true;
    script.defer = true;
    script.dataset.googleMapsPlaces = "1";
    script.addEventListener("load", () => {
      if (window.google?.maps) {
        resolve(window.google.maps);
        return;
      }

      reject(new Error("Google Maps JavaScript API is not available"));
    });
    script.addEventListener("error", () => reject(new Error("Google Maps script failed to load")));
    document.head.appendChild(script);
  });
}

function extractGoogleAddress(parts) {
  const byType = (type) =>
    Array.isArray(parts)
      ? parts.find((part) => Array.isArray(part.types) && part.types.includes(type))
      : null;

  const textValue = (part, preferShort = false) => {
    if (!part) {
      return "";
    }

    if (preferShort && typeof part.shortText === "string" && part.shortText !== "") {
      return part.shortText;
    }

    if (typeof part.longText === "string" && part.longText !== "") {
      return part.longText;
    }

    if (preferShort && typeof part.short_name === "string" && part.short_name !== "") {
      return part.short_name;
    }

    if (typeof part.long_name === "string" && part.long_name !== "") {
      return part.long_name;
    }

    return "";
  };

  const streetNumber = textValue(byType("street_number"));
  const route = textValue(byType("route"), true);
  const postalCode = textValue(byType("postal_code"));
  const city =
    textValue(byType("postal_town")) ||
    textValue(byType("locality")) ||
    textValue(byType("administrative_area_level_2")) ||
    "";

  const street = [route, streetNumber].filter(Boolean).join(" ").trim();

  return {
    street,
    postalCode: formatSwedishPostcode(postalCode),
    city,
  };
}

function waitForGooglePlacesApi(maxAttempts = 20, delayMs = 200) {
  return new Promise((resolve, reject) => {
    let attempts = 0;

    const check = () => {
      const places = google?.maps?.places;
      if (places && (typeof places.PlaceAutocompleteElement === "function" || typeof places.Autocomplete === "function")) {
        resolve({
          PlaceAutocompleteElement: places.PlaceAutocompleteElement,
          Autocomplete: places.Autocomplete,
        });
        return;
      }

      attempts += 1;
      if (attempts >= maxAttempts) {
        reject(new Error("Google Places API is not available"));
        return;
      }

      window.setTimeout(check, delayMs);
    };

    check();
  });
}

function initAddressAutocomplete() {
  if (!addressStreetField || !addressPostalCodeField || !addressCityField) {
    return;
  }

  const apiKey = String(siteConfig.googleMapsApiKey || "").trim();
  if (!apiKey) {
    return;
  }

  const countryCode = String(siteConfig.googleMapsAutocompleteCountry || "se").trim().toLowerCase();

  loadGoogleMapsPlaces(apiKey, countryCode)
    .then(() => waitForGooglePlacesApi())
    .then(async ({ PlaceAutocompleteElement, Autocomplete }) => {
      if (!isSafariBrowser && typeof PlaceAutocompleteElement === "function" && addressAutocompleteShell) {
        const widget = new PlaceAutocompleteElement({
          includedRegionCodes: [countryCode.toUpperCase()],
        });

        widget.setAttribute("name", "serviceAddressAutocomplete");
        widget.setAttribute("placeholder", "Börja med gatuadress");

        addressStreetField.type = "hidden";
        addressAutocompleteShell.prepend(widget);

        widget.addEventListener("gmp-select", async (event) => {
          const placePrediction = event.placePrediction || event.detail?.placePrediction;
          if (!placePrediction) {
            return;
          }

          const place = placePrediction.toPlace();
          await place.fetchFields({ fields: ["addressComponents", "formattedAddress"] });
          const address = extractGoogleAddress(place.addressComponents || []);

          addressStreetField.value = address.street || place.formattedAddress || "";

          if (address.postalCode) {
            addressPostalCodeField.value = address.postalCode;
          }

          if (address.city) {
            addressCityField.value = address.city;
          }
        });

        widget.addEventListener("blur", () => {
          const widgetStreet = getWidgetStreetValue(widget);
          if (widgetStreet && !String(addressStreetField.value || "").trim()) {
            addressStreetField.value = widgetStreet;
          }
        }, true);
      } else if (!isSafariBrowser && typeof Autocomplete === "function") {
        const autocomplete = new Autocomplete(addressStreetField, {
          fields: ["address_components", "formatted_address"],
          types: ["address"],
          componentRestrictions: { country: [countryCode] },
        });

        autocomplete.addListener("place_changed", () => {
          const place = autocomplete.getPlace();
          const address = extractGoogleAddress(place?.address_components || []);

          if (address.street) {
            addressStreetField.value = address.street;
          }

          if (address.postalCode) {
            addressPostalCodeField.value = address.postalCode;
          }

          if (address.city) {
            addressCityField.value = address.city;
          }
        });
      } else {
        if (addressHelpText) {
          addressHelpText.textContent = "Fyll i adress, postnummer och ort manuellt. Adresshjälpen används bara där den fungerar stabilt.";
        }
        return;
      }

      if (addressHelpText) {
        addressHelpText.textContent = "Börja skriva gatuadressen och välj ett förslag, så fylls postnummer och ort i automatiskt.";
      }
    })
    .catch((error) => {
      console.error("Adress-autocomplete kunde inte starta.", error);
      if (addressHelpText) {
        addressHelpText.textContent = "Adresshjälpen kunde inte starta just nu. Fyll gärna i postnummer och ort manuellt.";
      }
    });
}

if (addressPostalCodeField) {
  addressPostalCodeField.value = formatSwedishPostcode(addressPostalCodeField.value);

  addressPostalCodeField.addEventListener("input", () => {
    addressPostalCodeField.value = formatSwedishPostcode(addressPostalCodeField.value);
  });

  addressPostalCodeField.addEventListener("blur", () => {
    addressPostalCodeField.value = formatSwedishPostcode(addressPostalCodeField.value);
  });
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

if (offerForm) {
  offerForm.addEventListener("submit", async (event) => {
    const autocompleteWidget = addressAutocompleteShell?.querySelector("gmp-place-autocomplete");
    const usesWidget = Boolean(autocompleteWidget) && addressStreetField?.type === "hidden";
    const postalCodeValue = String(addressPostalCodeField?.value || "").trim();
    const cityValue = String(addressCityField?.value || "").trim();

    if (usesWidget) {
      const widgetStreetValue = getWidgetStreetValue(autocompleteWidget);
      if (widgetStreetValue && !String(addressStreetField?.value || "").trim()) {
        addressStreetField.value = widgetStreetValue;
      }
    }

    const missingAddressSelection =
      usesWidget
      && (
        !postalCodeValue
        || !cityValue
      );

    if (!missingAddressSelection) {
      const submitButton = offerForm.querySelector('button[type="submit"]');
      const formData = new FormData(offerForm);
      const payload = {};

      formData.forEach((value, key) => {
        payload[key] = typeof value === "string" ? value : String(value);
      });

      if (!payload["form-name"]) {
        payload["form-name"] = offerForm.getAttribute("name") || "quote-request";
      }

      event.preventDefault();

      if (submitButton instanceof HTMLButtonElement) {
        submitButton.disabled = true;
        submitButton.dataset.originalText = submitButton.textContent || "";
        submitButton.textContent = "Skickar...";
      }

      if (formStatus) {
        formStatus.hidden = true;
        formStatus.textContent = "";
      }

      fetch("/", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: encodeFormData(payload),
      })
        .then((response) => {
          if (!response.ok) {
            throw new Error(`Netlify form submit failed with status ${response.status}`);
          }

          window.location.assign("/tack/");
        })
        .catch((error) => {
          console.error("Formuläret kunde inte skickas.", error);
          showFormStatus(statusMessages.error, "error");
        })
        .finally(() => {
          if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = false;
            submitButton.textContent = submitButton.dataset.originalText || "Få prisförslag";
          }
        });

      return;
    }

    event.preventDefault();
    showFormStatus(
      "Välj ett adressförslag i listan så att postnummer och ort fylls i innan du skickar formuläret."
    );
    autocompleteWidget?.scrollIntoView({ behavior: "smooth", block: "center" });
  });
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

initAddressAutocomplete();
initCookieConsent();
