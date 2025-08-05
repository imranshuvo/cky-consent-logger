<?php
/**
 * Plugin Name: CookieYes Consent Logger
 * Description: GDPR-compliant logging of CookieYes consent including acceptance, rejection, updates, and proof generation.
 * Version: 1.0.0
 * Author: Imran Khan, Webkonsulenterne
 */

defined('ABSPATH') || exit;

define('CCL_PATH', plugin_dir_path(__FILE__));
define('CCL_URL', plugin_dir_url(__FILE__));

require_once CCL_PATH . 'includes/db.php';
require_once CCL_PATH . 'includes/logger.php';
require_once CCL_PATH . 'includes/admin.php';
require_once CCL_PATH . 'includes/pdf.php';

register_activation_hook(__FILE__, 'ccl_create_consent_log_table');



add_action('wp_head', function(){
    ?>
    <style>
        html body .cky-consent-container {
            top: 50%;
            left: 50%;
            bottom: auto;
            transform: translate(-50%, -50%);
            z-index: 99999999;
        }
        .cookieYesNotClicked .cky-overlay.cky-hide {
		    display: block;
		}
    </style>
    <?php 
});

add_action('wp_footer', function () {
    ?>
<script>
(function () {
	function getCookie(name) {
		const value = `; ${document.cookie}`;
		const parts = value.split(`; ${name}=`);
		if (parts.length === 2) return parts.pop().split(';').shift();
	}
	
	function isBrave() {
		// Brave injects a 'brave' object into navigator
		// It's generally considered reliable for Brave detection
		return (navigator.brave && navigator.brave.isBrave);
	}


	function isConsentAlreadyLogged(consentId) {
		const last = getCookie('ccl_consent_logged');
		return consentId && last === consentId;
	}

	function markConsentLogged(consentId) {
		if (!consentId) return;
		document.cookie = `ccl_consent_logged=${consentId}; max-age=31536000; path=/`;
	}

	function clearConsentLoggedFlag() {
		document.cookie = `ccl_consent_logged=; max-age=0; path=/`;
	}

	function determineStatus(consent) {
		const optionalKeys = ['functional', 'analytics', 'performance', 'advertisement'];
		const allRejected = optionalKeys.every(key => consent.categories?.[key] === false);
		return allRejected ? 'rejected' : 'accepted';
	}

	function logConsent(status, consentData) {
		const consentId = consentData?.consentID || '';
		if (!consentId || isConsentAlreadyLogged(consentId)) return;

		// Prevent double logging via page reload
		if (sessionStorage.getItem('ccl_logged_this_session') === 'yes') return;
		sessionStorage.setItem('ccl_logged_this_session', 'yes');

		fetch('/wp-admin/admin-ajax.php?action=ccl_log_consent', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				status: status,
				categories: consentData.categories,
				consentId: consentId
			})
		}).then(response => {
			if (response.ok) {
				markConsentLogged(consentId);
			}
		});
	}





	function processConsent() {
		const consent = typeof getCkyConsent === 'function' ? getCkyConsent() : null;
		if (!consent?.isUserActionCompleted || !consent?.consentID) return;

		document.body.classList.remove('cookieYesNotClicked');

		const status = determineStatus(consent);
		logConsent(status, consent);
	}

	// Run everything after DOM is ready
	document.addEventListener("DOMContentLoaded", function () {
		// Add body class if user hasn’t interacted yet
		const consent = typeof getCkyConsent === 'function' ? getCkyConsent() : null;
		//console.log(consent);
		if (!consent?.isUserActionCompleted) {
			document.body.classList.add('cookieYesNotClicked');
		}
		if(isBrave()){
			document.body.classList.remove('cookieYesNotClicked');
		}



		// 1. On initial load — log once if needed
		let tries = 0;
		const maxTries = 10;
		const retryInterval = setInterval(() => {
			const consent = typeof getCkyConsent === 'function' ? getCkyConsent() : null;
			if (consent?.isUserActionCompleted && consent?.consentID) {
				processConsent();
				clearInterval(retryInterval);
			}
			if (++tries >= maxTries) clearInterval(retryInterval);
		}, 500);

		// zaraz.set("google_consent_default", {
		// 	ad_storage: 'denied',
		// 	ad_user_data: 'denied',
		// 	ad_personalization: 'denied',
		// 	analytics_storage: 'denied'
		// });

		// const consent = typeof getCkyConsent === 'function' ? getCkyConsent() : null;
		// if (consent?.isUserActionCompleted) {
		// 	zaraz.set("google_consent", {
		// 		ad_storage: consent.categories?.advertisement ? 'granted' : 'denied',
		// 		ad_user_data: consent.categories?.advertisement ? 'granted' : 'denied',
		// 		ad_personalization: consent.categories?.advertisement ? 'granted' : 'denied',
		// 		analytics_storage: consent.categories?.analytics ? 'granted' : 'denied'
		// 	});
		// }

		// 2. On user updating preferences (Accept/Reject/Customize)
		document.addEventListener('cookieyes_consent_update', function () {
			// const consent = typeof getCkyConsent === 'function' ? getCkyConsent() : null;
			// if (!consent?.isUserActionCompleted) return;

			// zaraz.set("google_consent_update", {
			// 	ad_storage: consent.categories?.advertisement ? 'granted' : 'denied',
			// 	ad_user_data: consent.categories?.advertisement ? 'granted' : 'denied',
			// 	ad_personalization: consent.categories?.advertisement ? 'granted' : 'denied',
			// 	analytics_storage: consent.categories?.analytics ? 'granted' : 'denied'
			// });

			clearConsentLoggedFlag(); // allow re-logging
			processConsent(); // will also remove body class
		});
	});

})();
</script>
    <?php
});
