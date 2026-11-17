class ReservationWizard {
  constructor(config) {
    ReservationDebug.log(
      "🛠️ ReservationWizard constructor called",
      "INIT",
      config
    );
    this.config = config;
    this.currentStep = 1;
    ReservationDebug.currentStep = this.currentStep; // Make available to debug utility

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

    ReservationDebug.log("🏁 Starting wizard initialization", "INIT", {
      currentStep: this.currentStep,
      initialData: this.data,
    });

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
          ReservationDebug.log("🌿 Branches loaded successfully", "AJAX", {
            branchCount: response.data.length,
            branches: response.data,
          });
          this.renderBranches(response.data);
        } else {
          ReservationDebug.log("🌿 Branches response not successful", "AJAX", {
            success: response.success,
            data: response.data,
            fullResponse: response,
          });
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
          responseText: xhr?.responseText,
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
}
