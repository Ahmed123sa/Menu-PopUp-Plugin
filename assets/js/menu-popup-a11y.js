/**
 * Menu Pop-up: keyboard and screen-reader support
 * - Enter / Space activate the custom buttons (cart icon, branch cards)
 * - Dialogs: focus moves inside when opened, Tab stays inside, Escape closes,
 *   focus returns to the control that opened them
 */
(function () {
  "use strict";

  var DIALOGS =
    "#cart-modal, #order-form-modal, #order-confirmation-modal, #order-review-modal, " +
    "#globalVideoModal, #branchChangeModal, .cart-flyout";
  var CLOSERS =
    ".cart-modal-close, .order-form-close, .video-modal-close, .branch-change-modal-close, .cart-flyout-close";
  var FOCUSABLE =
    'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

  var openStack = [];

  function isVisible(el) {
    if (!el || !el.getClientRects().length) return false;
    var cs = window.getComputedStyle(el);
    return cs.visibility !== "hidden" && cs.display !== "none" && cs.opacity !== "0";
  }

  function focusables(root) {
    return Array.prototype.filter.call(root.querySelectorAll(FOCUSABLE), isVisible);
  }

  function dialogContent(el) {
    return (
      el.querySelector(
        ".cart-modal-content, .order-form-content, .order-confirmation-content, .order-review-content, .video-modal-container, .branch-change-modal"
      ) || el
    );
  }

  function onOpen(el) {
    if (openStack.some(function (d) { return d.el === el; })) return;
    var entry = { el: el, opener: document.activeElement };
    openStack.push(entry);
    var root = dialogContent(el);
    window.setTimeout(function () {
      var items = focusables(root);
      var target = items.length ? items[0] : root;
      if (target === root && !root.hasAttribute("tabindex")) {
        root.setAttribute("tabindex", "-1");
      }
      if (target && target.focus) target.focus();
    }, 30);
  }

  function onClose(el) {
    for (var i = openStack.length - 1; i >= 0; i--) {
      if (openStack[i].el === el) {
        var opener = openStack[i].opener;
        openStack.splice(i, 1);
        if (opener && document.body.contains(opener) && isVisible(opener) && opener.focus) {
          opener.focus();
        }
        return;
      }
    }
  }

  function sync(el) {
    if (isVisible(el)) onOpen(el);
    else onClose(el);
    if (el.classList.contains("cart-flyout")) {
      var icon = document.querySelector(".cart-toggle-icon");
      if (icon) icon.setAttribute("aria-expanded", isVisible(el) ? "true" : "false");
    }
  }

  // Menu items that open a video behave like buttons: reachable and usable with the keyboard
  function enhanceItems(root) {
    var list = [];
    if (root.matches && root.matches(".menu-item[data-video]")) list.push(root);
    if (root.querySelectorAll) {
      Array.prototype.push.apply(list, root.querySelectorAll(".menu-item[data-video]"));
    }
    list.forEach(function (el) {
      if (el.getAttribute("data-video") && !el.hasAttribute("tabindex")) {
        el.setAttribute("tabindex", "0");
        el.setAttribute("role", "button");
        var title = el.getAttribute("data-title");
        if (title) el.setAttribute("aria-label", title);
      }
    });
  }

  function check(root) {
    if (!root || root.nodeType !== 1) return;
    enhanceItems(root);
    if (root.matches(DIALOGS)) sync(root);
    var inner = root.querySelectorAll ? root.querySelectorAll(DIALOGS) : [];
    for (var i = 0; i < inner.length; i++) sync(inner[i]);
  }

  var pending = false;
  var queue = [];
  function flush() {
    pending = false;
    var items = queue;
    queue = [];
    items.forEach(function (n) {
      if (document.body.contains(n)) check(n);
      else if (n.matches && n.matches(DIALOGS)) onClose(n);
    });
  }

  function init() {
    enhanceItems(document.body);
    if (!window.MutationObserver) return;
    var mo = new MutationObserver(function (list) {
      list.forEach(function (m) {
        if (m.type === "attributes" && m.target.matches && m.target.matches(DIALOGS)) {
          queue.push(m.target);
        } else if (m.type === "childList") {
          Array.prototype.forEach.call(m.addedNodes, function (n) {
            if (n.nodeType === 1) queue.push(n);
          });
        }
      });
      if (queue.length && !pending) {
        pending = true;
        window.requestAnimationFrame(flush);
      }
    });
    mo.observe(document.body, {
      subtree: true,
      childList: true,
      attributes: true,
      attributeFilter: ["class", "style"],
    });
  }

  document.addEventListener("keydown", function (e) {
    var t = e.target;
    // Activate custom buttons with Enter / Space
    if ((e.key === "Enter" || e.key === " ") && t && t.matches && t.matches(".branch-card, .cart-toggle-icon, .menu-item[data-video]")) {
      e.preventDefault();
      t.click();
      return;
    }

    if (!openStack.length) return;
    var top = openStack[openStack.length - 1].el;
    if (!document.body.contains(top)) {
      openStack.pop();
      return;
    }

    if (e.key === "Escape") {
      var closer = top.querySelector(CLOSERS);
      if (closer) closer.click();
      return;
    }

    if (e.key === "Tab") {
      var root = dialogContent(top);
      var items = focusables(root);
      if (!items.length) {
        e.preventDefault();
        return;
      }
      var first = items[0];
      var last = items[items.length - 1];
      var active = document.activeElement;
      if (e.shiftKey && (active === first || !root.contains(active))) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && (active === last || !root.contains(active))) {
        e.preventDefault();
        first.focus();
      }
    }
  });

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
