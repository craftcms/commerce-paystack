function initCheckout() {
  // Because this might get executed before Paystack is loaded.
  if (typeof Paystack === "undefined") {
    setTimeout(initCheckout, 200);
  } else {
    console.log('Paystack JS loaded');
  }
}

initCheckout();