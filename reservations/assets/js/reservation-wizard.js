// Debug logging utility for JavaScript
const ReservationDebug = {
  log: function (message, category = "FRONTEND", data = null, level = "INFO") {
    const timestamp = new Date().toISOString();
    const formattedMessage = `[JS] ${timestamp} [${category}] ${message}`;

    if (data) {
      const dataStr = typeof data === "object" ? JSON.stringify(data) : data;
      console.log(formattedMessage, dataStr);
    } else {
      console.log(formattedMessage);
    }

    // Send debug info to server for logging (only in admin)
    if (
      typeof menuPopupReservation !== "undefined" &&
      window.location.href.indexOf("wp-admin") !== -1
    ) {
      jQuery.post(menuPopupReservation.ajax_url, {
        action: "menu_popup_debug_js_log",
        message: formattedMessage,
        category: category,
        data: data,
        nonce: menuPopupReservation.nonce,
      });
    }
  },

  logStep: function (step, data = {}) {
    this.log(`STEP ${this.currentStep}.${step}`, "WIZARD", data);
  },

  logError: function (message, error) {
    console.error(`[JS ERROR] ${message}`, error);
    this.log(`ERROR: ${message}`, "ERROR", error);
  },
};

class ReservationWizard {
  constructor(config) {
    ReservationDebug.log(
      "🛠️ ReservationWizard constructor called",
      "INIT",
      config
    );
    this.config = config;
    this.currentStep = 1;
    ReservationDebug.currentStep = this.currentStep;

    this.data = {
      branch_id: null,
      party_size: 2,
      date: null,
      time: null,
      table_id: null,
      table_name: null,
      customer_name: null,
      customer_phone: null,
      special_requests: null,
    };

    this.init();
  }

  init() {
    ReservationDebug.log("🔄 Starting wizard initialization", "INIT");
    this.cacheDOM();
    this.bindEvents();
    this.loadBranches();
    this.syncPartySize();

    ReservationDebug.log("✅ Wizard initialization completed", "INIT", {
      domElements: {
        container: !!this.container,
        steps: this.steps?.length || 0,
        branchSelect: !!this.branchSelect,
      },
    });
  }

  loadBranches() {
    ReservationDebug.log("🌿 Loading branches - Starting AJAX call", "AJAX", {
      ajaxUrl: this.config.ajaxUrl,
      action: "menu_popup_get_branches",
    });

    jQuery.ajax({
      url: this.config.ajaxUrl,
      type: "GET",
      data: {
        action: "menu_popup_get_branches",
      },
      success: (response, textStatus, xhr) => {
        ReservationDebug.log("🌿 Branches AJAX Success", "AJAX", {
          response: response,
          status: xhr.status,
          statusText: xhr.statusText,
        });

        if (response.success && response.data) {
          this.renderBranches(response.data);
        } else {
          this.branchSelect.innerHTML =
            '<option value="">Unable to load branches (API error)</option>';
        }
      },
      error: (xhr, status, error) => {
        ReservationDebug.logError("🌿 Branches AJAX Failed", {
          url: this.config.ajaxUrl,
          xhr: xhr,
          status: status,
          error: error,
        });
        this.branchSelect.innerHTML = `<option value="">Unable to load branches (HTTP ${xhr.status})</option>`;
      },
    });
  }

  renderBranches(branches) {
    this.branchSelect.innerHTML = '<option value="">Select Branch</option>';
    branches.forEach((branch) => {
      this.branchSelect.innerHTML += `<option value="${branch.id}">${branch.name}</option>`;
    });
  }

  cacheDOM() {
    this.container = document.querySelector(this.config.selector);
    this.steps = this.container.querySelectorAll(".wizard-step");
    this.nextBtns = this.container.querySelectorAll(".next-step");
    this.prevBtns = this.container.querySelectorAll(".prev-step");
    this.branchSelect = this.container.querySelector(
      'select[name="branch_id"]'
    );
    this.dateInput = this.container.querySelector(
      'input[name="reservation_date"]'
    );
    this.timeSlotsContainer = this.container.querySelector(".time-slots-grid");
    this.partySizeInput = this.container.querySelector(
      'input[name="party_size"]'
    );
    this.submitButton = this.container.querySelector(".submit-reservation");
  }

  bindEvents() {
    this.nextBtns.forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.preventDefault();
        this.nextStep();
      });
    });

    this.prevBtns.forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.preventDefault();
        this.prevStep();
      });
    });

    if (this.branchSelect) {
      this.branchSelect.addEventListener("change", (e) => {
        this.data.branch_id = e.target.value;
        this.updateBranchSummary();
        this.resetTimeSlots();
      });
    }

    if (this.dateInput) {
      this.dateInput.addEventListener("change", (e) => {
        this.data.date = e.target.value;
        this.loadTimeSlots();
      });
    }

    if (this.submitButton) {
      this.submitButton.addEventListener("click", (e) => {
        e.preventDefault();
        this.submitReservation();
      });
    }
  }

  syncPartySize() {
    const partySizeInput2 = this.container.querySelector(
      'input[name="party_size_2"]'
    );
    if (this.partySizeInput && partySizeInput2) {
      partySizeInput2.value = this.partySizeInput.value;
    }
  }

  updateBranchSummary() {
    const branchNameEl = this.container.querySelector("#selected-branch-name");
    const partySizeEl = this.container.querySelector("#selected-party-size");

    if (branchNameEl) {
      if (this.data.branch_id) {
        const selectedOption =
          this.branchSelect.options[this.branchSelect.selectedIndex];
        branchNameEl.textContent = selectedOption
          ? selectedOption.text
          : "Unknown Branch";
      } else {
        branchNameEl.textContent = "Select branch first";
      }
    }

    if (partySizeEl) {
      partySizeEl.textContent = this.data.party_size;
    }
  }

  nextStep() {
    if (this.currentStep < 4) {
      this.currentStep++;
      this.updateUI();
    }
  }

  prevStep() {
    if (this.currentStep > 1) {
      this.currentStep--;
      this.updateUI();
    }
  }

  updateUI() {
    this.steps.forEach((step, index) => {
      if (index + 1 === this.currentStep) {
        step.classList.add("active");
      } else {
        step.classList.remove("active");
      }
    });
  }

  submitReservation() {
    const name = this.container.querySelector(
      'input[name="customer_name"]'
    )?.value;
    const phone = this.container.querySelector(
      'input[name="customer_phone"]'
    )?.value;
    const requests = this.container.querySelector(
      'textarea[name="special_requests"]'
    )?.value;

    if (!name || !phone) {
      alert("Please fill in all required fields.");
      return;
    }

    this.submitButton.textContent = "Processing...";
    this.submitButton.disabled = true;

    jQuery.ajax({
      url: this.config.ajaxUrl,
      type: "POST",
      data: {
        action: "menu_popup_submit_reservation",
        nonce: this.config.nonce,
        branch_id: this.data.branch_id,
        party_size: this.data.party_size,
        reservation_date: this.data.date,
        time: this.data.time,
        table_id: this.data.table_id,
        customer_name: name,
        customer_phone: phone,
        special_requests: requests,
      },
      success: (response) => {
        if (response.success) {
          this.showSuccess(response.data);
        } else {
          alert(response.data || "An error occurred");
          this.submitButton.textContent = "Confirm Reservation";
          this.submitButton.disabled = false;
        }
      },
      error: () => {
        alert("An error occurred. Please try again.");
        this.submitButton.textContent = "Confirm Reservation";
        this.submitButton.disabled = false;
      },
    });
  }

  showSuccess(message) {
    this.container.innerHTML = `
      <div class="wizard-success">
        <div class="success-icon">✓</div>
        <h3>Reservation Confirmed!</h3>
        <p>${message}</p>
        <div class="reservation-details">
          <div class="detail-item"><strong>Date:</strong> ${this.formatDate(
            this.data.date
          )}</div>
          <div class="detail-item"><strong>Time:</strong> ${
            this.data.time
          }</div>
          <div class="detail-item"><strong>Table:</strong> ${
            this.data.table_name || "Assigned"
          }</div>
          <div class="detail-item"><strong>Party Size:</strong> ${
            this.data.party_size
          } guests</div>
        </div>
        <button class="btn btn-primary" onclick="location.reload()">Make Another Reservation</button>
      </div>
    `;
  }

  formatDate(dateString) {
    if (!dateString) return "TBD";
    const date = new Date(dateString);
    return date.toLocaleDateString("en-US", {
      weekday: "long",
      year: "numeric",
      month: "long",
      day: "numeric",
    });
  }

  resetTimeSlots() {
    this.timeSlotsContainer.innerHTML = "<p>Please select a date first.</p>";
    this.data.time = null;
    this.data.table_id = null;
    this.data.table_name = null;
  }

  loadTimeSlots() {
    if (!this.data.branch_id || !this.data.date) return;

    this.timeSlotsContainer.innerHTML =
      '<div class="loading">Loading slots...</div>';

    jQuery.ajax({
      url: this.config.ajaxUrl,
      type: "POST",
      data: {
        action: "menu_popup_get_time_slots",
        branch_id: this.data.branch_id,
        date: this.data.date,
        party_size: this.data.party_size,
      },
      success: (response) => {
        if (response.success && response.data?.length > 0) {
          this.renderTimeSlots(response.data);
        } else {
          this.timeSlotsContainer.innerHTML =
            '<div class="error">No time slots available</div>';
        }
      },
      error: () => {
        this.timeSlotsContainer.innerHTML =
          '<div class="error">Unable to load time slots</div>';
      },
    });
  }

  renderTimeSlots(slots) {
    this.timeSlotsContainer.innerHTML = "";
    slots.forEach((slot) => {
      const btn = document.createElement("button");
      btn.className = "time-slot-btn";
      btn.textContent = slot;
      btn.type = "button";
      btn.addEventListener("click", () => {
        this.container
          .querySelectorAll(".time-slot-btn")
          .forEach((b) => b.classList.remove("selected"));
        btn.classList.add("selected");
        this.data.time = slot;
        this.loadAvailableTables();
      });
      this.timeSlotsContainer.appendChild(btn);
    });
  }

  loadAvailableTables() {
    if (!this.data.branch_id || !this.data.date || !this.data.time) return;

    const customerFloorPlan = document.getElementById("customer-floor-plan");
    if (customerFloorPlan) {
      customerFloorPlan.innerHTML =
        '<div class="loading">Loading available tables...</div>';
    }

    jQuery.ajax({
      url: this.config.ajaxUrl,
      type: "POST",
      data: {
        action: "menu_popup_get_available_tables",
        branch_id: this.data.branch_id,
        date: this.data.date,
        time: this.data.time,
        party_size: this.data.party_size,
      },
      success: (response) => {
        if (response.success && response.data?.length > 0) {
          this.renderTableFloorPlan(response.data);
        } else {
          if (customerFloorPlan) {
            customerFloorPlan.innerHTML =
              '<div class="error">No tables available</div>';
          }
        }
      },
      error: () => {
        if (customerFloorPlan) {
          customerFloorPlan.innerHTML =
            '<div class="error">Unable to load tables</div>';
        }
      },
    });
  }

  renderTableFloorPlan(tables) {
    const customerFloorPlan = document.getElementById("customer-floor-plan");
    if (!customerFloorPlan) return;

    customerFloorPlan.innerHTML = "";
    customerFloorPlan.className = "customer-floor-plan active";

    // CLEAN START - No extra container div that could conflict
    const floorContainer = customerFloorPlan; // USE FLOOR PLAN DIRECTLY

    const diningAreas = {
      vip: { name: "👑 VIP Area", icon: "👑", tables: [] },
      indoor: { name: "🏠 Indoor Area", icon: "🏠", tables: [] },
      terrace: { name: "🌳 Terrace", icon: "🌳", tables: [] },
      bar: { name: "🍷 Bar Area", icon: "🍷", tables: [] },
    };

    tables.forEach((table) => {
      const zoneKey = table.zone || "indoor";
      if (diningAreas[zoneKey]) {
        diningAreas[zoneKey].tables.push(table);
      }
    });

    let zonesShown = 0;

    Object.keys(diningAreas).forEach((areaKey) => {
      const areaData = diningAreas[areaKey];
      if (areaData.tables.length === 0) return;

      zonesShown++;

      const areaDiv = document.createElement("div");
      areaDiv.className = `customer-dining-area customer-area-${areaKey}`;

      const areaHeader = document.createElement("div");
      areaHeader.className = "customer-area-header";
      areaHeader.innerHTML = `
        <span class="area-icon">${areaData.icon}</span>
        <span class="area-name">${areaData.name}</span>
        <span class="table-count">${areaData.tables.length} table${
        areaData.tables.length === 1 ? "" : "s"
      }</span>
      `;
      areaDiv.appendChild(areaHeader);

      const areaLayout = document.createElement("div");
      areaLayout.className = "customer-area-layout";

      areaData.tables.forEach((table) => {
        const tableDiv = document.createElement("div");
        tableDiv.className = `customer-restaurant-table available ${this.getTableSizeClass(
          table.capacity
        )}`;
        tableDiv.setAttribute("data-table-id", table.id);
        tableDiv.setAttribute("data-table-name", table.name);
        tableDiv.setAttribute("data-capacity", table.capacity);
        tableDiv.setAttribute("data-zone", areaKey);

        tableDiv.addEventListener("click", () => {
          this.selectCustomerTable(
            table.id,
            table.name,
            table.capacity,
            areaKey
          );
        });

        // SIMPLIFIED: Clean table structure with unique class names
        tableDiv.innerHTML = `
          <div class="customer-table-visual">
            <div class="customer-table-shape shape-${table.shape || "round"}">${
          table.name
        }</div>
            <div class="customer-table-status">
              <span class="customer-status-dot available"></span>
              <span class="customer-status-text">Available</span>
            </div>
          </div>
          <div class="customer-table-info">
            <div class="customer-table-seats">${table.capacity} seats</div>
            <div class="customer-table-zone">${
              areaKey.charAt(0).toUpperCase() + areaKey.slice(1)
            }</div>
          </div>
        `;

        areaLayout.appendChild(tableDiv);
      });

      areaDiv.appendChild(areaLayout);
      floorContainer.appendChild(areaDiv);
    });

    // REMOVED decoration as it might cause layout issues

    const instructionsEl = document.querySelector(
      ".table-selection-instructions"
    );
    if (instructionsEl) {
      instructionsEl.innerHTML = `
        <div style="display: flex; gap: 20px; margin-bottom: 15px;">
          <div><span style="color: #38a169; font-weight: bold;">🟢 Green:</span> Available for your party</div>
          <div><span style="color: #e53e3e; font-weight: bold;">🔴 Red:</span> Unavailable or too small</div>
        </div>
        <p><strong>Click any table to select it.</strong> Tables organized by ${zonesShown} dining area${
        zonesShown === 1 ? "" : "s"
      }.</p>
      `;
    }
  }

  getTableSizeClass(capacity) {
    if (capacity <= 2) return "table-small";
    if (capacity <= 4) return "table-medium";
    if (capacity <= 6) return "table-large";
    return "table-xl";
  }

  selectCustomerTable(tableId, tableName, capacity, zone = "unknown") {
    document.querySelectorAll(".customer-restaurant-table").forEach((el) => {
      el.classList.remove("selected");
    });

    const selectedTable = document.querySelector(
      `[data-table-id="${tableId}"]`
    );
    if (selectedTable) {
      selectedTable.classList.add("selected");
      selectedTable.scrollIntoView({ behavior: "smooth", block: "center" });
    }

    this.data.table_id = tableId;
    this.data.table_name = tableName;
    this.data.table_zone = zone;

    const display = document.getElementById("selected-table-display");
    if (display) {
      const tableNumberEl = display.querySelector(".table-number");
      const tableDetailsEl = display.querySelector(".table-details");

      if (tableNumberEl) {
        tableNumberEl.textContent = `${tableName} in ${
          zone.charAt(0).toUpperCase() + zone.slice(1)
        }`;
      }
      if (tableDetailsEl) {
        tableDetailsEl.textContent = ` (${capacity} seats)`;
      }
    }

    const nextBtn = document.getElementById("table-selection-next");
    if (nextBtn) {
      nextBtn.disabled = false;
      nextBtn.textContent = "Next: Details";
    }
  }

  addRestaurantDecorations(container) {
    const entrance = document.createElement("div");
    entrance.className = "layout-decoration entrance";
    entrance.textContent = "🪑";
    entrance.title = "Entrance";

    container.appendChild(entrance);
  }
}

jQuery(document).ready(function ($) {
  if ($("#reservation-wizard").length) {
    const config =
      typeof window.menuPopupReservation !== "undefined"
        ? {
            selector: "#reservation-wizard",
            ajaxUrl:
              window.menuPopupReservation.ajax_url ||
              window.menuPopupReservation.ajaxUrl,
            nonce: window.menuPopupReservation.nonce,
            current_url: window.menuPopupReservation.current_url,
          }
        : {
            selector: "#reservation-wizard",
            ajaxUrl: window.ajaxurl || "/wp-admin/admin-ajax.php",
            nonce: window.menuPopupAjax ? window.menuPopupAjax.nonce : "",
            current_url: window.location.href,
          };

    new ReservationWizard(config);
  }
});
