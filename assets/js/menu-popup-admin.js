// Global edit functions defined immediately (before jQuery wrapper)
window.openEditCategoryForm = function (categoryId) {
  const categoryItems = jQuery("[data-id='" + categoryId + "']");
  if (categoryItems.length === 0) return;

  const categoryItem = categoryItems.first();
  const categoryName = categoryItem.find(".category-name").text().trim();
  const categorySlug = categoryItem.find(".category-slug").text().trim();
  const categoryDescription = categoryItem
    .find(".category-description")
    .text()
    .trim();
  const categoryImage = categoryItem.find("img").attr("src") || "";

  // Check if edit form already exists for this category
  if (jQuery("#edit-category-form-" + categoryId).length > 0) {
    window.closeEditCategoryForm(categoryId);
    return;
  }

  // Close any other edit forms first
  jQuery(".edit-category-form").remove();

  // Create inline edit form
  const editForm =
    jQuery(`<div id="edit-category-form-${categoryId}" class="edit-category-form" style="margin-top: 10px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
      <h4 style="margin: 0 0 10px 0; color: #23282d;">Edit Category</h4>
      <table class="form-table">
        <tr>
          <th><label for="edit_category_name_${categoryId}">Name</label></th>
          <td><input type="text" id="edit_category_name_${categoryId}" value="${categoryName}" size="50" /></td>
        </tr>
        <tr>
          <th><label for="edit_category_slug_${categoryId}">Slug</label></th>
          <td><input type="text" id="edit_category_slug_${categoryId}" value="${categorySlug}" size="50" /></td>
        </tr>
        <tr>
          <th><label for="edit_category_description_${categoryId}">Description</label></th>
          <td><textarea id="edit_category_description_${categoryId}" rows="3" cols="50">${categoryDescription}</textarea></td>
        </tr>
        <tr>
          <th><label for="edit_category_image_${categoryId}">Image</label></th>
          <td>
            <input type="text" id="edit_category_image_${categoryId}" value="${categoryImage}" size="50" placeholder="https://example.com/image.jpg" />
            <button type="button" class="button" onclick="window.openEditImageUploader('${categoryId}')">Upload Image</button>
            <div id="edit_image_preview_${categoryId}">${
      categoryImage
        ? `<img src="${categoryImage}" style="max-width: 150px; margin-top: 10px;" />`
        : ""
    }</div>
          </td>
        </tr>
      </table>
      <p class="submit">
        <button type="button" class="button button-primary" onclick="window.updateCategory('${categoryId}')" id="update-btn-${categoryId}">Update Category</button>
        <button type="button" class="button" onclick="window.closeEditCategoryForm('${categoryId}')">Cancel</button>
      </p>
    </div>`);

  // Insert edit form after the category item
  categoryItem.after(editForm);
  categoryItem.hide();

  // Auto-generate slug from name when editing
  jQuery(document).on(
    "input",
    "#edit_category_name_" + categoryId,
    function () {
      const name = jQuery(this).val();
      const slug = name
        .toLowerCase()
        .replace(/[^a-z0-9\s-]/g, "")
        .replace(/\s+/g, "-")
        .replace(/-+/g, "-")
        .trim("-");
      jQuery("#edit_category_slug_" + categoryId).val(slug);
    }
  );
};

window.closeEditCategoryForm = function (categoryId) {
  jQuery("#edit-category-form-" + categoryId).remove();
  jQuery("[data-id='" + categoryId + "']").show();
};

window.updateCategory = function (categoryId) {
  const name = jQuery("#edit_category_name_" + categoryId).val();
  const slug = jQuery("#edit_category_slug_" + categoryId).val();
  const description = jQuery("#edit_category_description_" + categoryId).val();
  const image = jQuery("#edit_category_image_" + categoryId).val();

  const nonce = window.menuPopupAdminAjax?.nonce;

  if (!name || !slug) {
    showAdminNotice("Please fill in all required fields", "error");
    return;
  }

  if (!/^[a-z0-9-]+$/.test(slug)) {
    showAdminNotice(
      "Category slug can only contain lowercase letters, numbers, and hyphens",
      "error"
    );
    return;
  }

  const updateBtn = jQuery("#update-btn-" + categoryId);
  const originalText = updateBtn.text();
  updateBtn.text("Updating...").prop("disabled", true);

  jQuery.ajax({
    url: window.menuPopupAdminAjax.ajaxurl || ajaxurl,
    type: "POST",
    data: {
      action: "update_category",
      category_id: categoryId,
      name: name,
      slug: slug,
      description: description,
      image: image,
      nonce: nonce,
    },
    success: function (response) {
      if (response.success) {
        const categoryItem = jQuery("[data-id='" + categoryId + "']");
        categoryItem.find(".category-name").text(name);
        categoryItem.find(".category-slug").text(slug);

        const descSpan = categoryItem.find(".category-description");
        if (description.trim()) {
          if (descSpan.length) descSpan.text(description);
          else
            categoryItem
              .find(".category-meta")
              .append(
                `<span class="category-description">${description}</span>`
              );
        } else descSpan.remove();

        const img = categoryItem.find("img");
        if (image.trim()) {
          if (img.length) img.attr("src", image);
          else
            categoryItem
              .find(".category-meta")
              .append(
                `<br><img src="${image}" style="max-width: 50px; margin-top: 5px;" />`
              );
        } else {
          img.remove();
          categoryItem.find(".category-meta br").remove();
        }

        window.closeEditCategoryForm(categoryId);
        showAdminNotice("Category updated successfully", "success");
      } else {
        showAdminNotice(response.data || "Failed to update category", "error");
      }
    },
    error: function () {
      showAdminNotice("Failed to update category", "error");
    },
    complete: function () {
      updateBtn.text(originalText).prop("disabled", false);
    },
  });
};

window.openEditImageUploader = function (categoryId) {
  const mediaUploader = wp.media({
    title: "Select Category Image",
    button: { text: "Use this image" },
    multiple: false,
  });

  mediaUploader.on("select", function () {
    const attachment = mediaUploader.state().get("selection").first().toJSON();
    jQuery("#edit_category_image_" + categoryId).val(attachment.url);
    jQuery("#edit_image_preview_" + categoryId).html(
      `<img src="${attachment.url}" style="max-width: 150px; margin-top: 10px;" />`
    );
  });

  mediaUploader.open();
};

function showAdminNotice(message, type = "info") {
  const noticeClass = `notice notice-${type} is-dismissible`;
  const notice = jQuery(`<div class="${noticeClass}">
      <p>${message}</p>
      <button type="button" class="notice-dismiss">
        <span class="screen-reader-text">Dismiss this notice.</span>
      </button>
    </div>`);

  jQuery(".wrap h1").after(notice);

  setTimeout(() => {
    notice.fadeOut(() => notice.remove());
  }, 5000);

  jQuery(".wrap").on("click", ".notice-dismiss", function () {
    jQuery(this)
      .closest(".notice")
      .fadeOut(() => jQuery(this).remove());
  });
}

/**
 * Menu Pop-up Admin JavaScript
 * Handles admin interface interactions
 */

(function ($) {
  "use strict";

  class MenuPopupAdmin {
    constructor() {
      this.init();
    }

    init() {
      this.bindEvents();
      this.setupMediaUploader();
      this.setupTooltips();
      this.updateGalleryIndices(); // Initialize gallery indices
    }

    bindEvents() {
      // Handle category dropdown changes
      $("#menu_item_category").on("change", (e) => {
        this.handleCategoryChange(e);
      });

      // Handle language changes
      $("#menu_item_language").on("change", (e) => {
        this.handleLanguageChange(e);
      });

      // Auto-generate video URL from YouTube/Vimeo link
      $("#menu_item_video").on("input", (e) => {
        this.handleVideoUrlInput(e);
      });

      // Validate form before submit
      $("form#post").on("submit", (e) => {
        this.validateForm(e);
      });

      // Handle gallery functionality
      $(document).on("click", "#add-gallery-image", (e) => {
        e.preventDefault();
        this.addGalleryImage();
      });

      $(document).on("click", ".remove-gallery-image", (e) => {
        e.preventDefault();
        this.removeGalleryImage(e);
      });

      $(document).on("click", ".move-up-gallery-image", (e) => {
        e.preventDefault();
        this.moveGalleryImage(e, "up");
      });

      $(document).on("click", ".move-down-gallery-image", (e) => {
        e.preventDefault();
        this.moveGalleryImage(e, "down");
      });

      // Gallery media uploader
      $(document).on("click", ".menu-upload-gallery-btn", (e) => {
        e.preventDefault();
        this.openGalleryMediaUploader(e);
      });

      // Size & Price Variations functionality
      $(document).on("click", "#add-variation", (e) => {
        e.preventDefault();
        this.addSizeVariation();
      });

      $(document).on("click", ".remove-variation", (e) => {
        e.preventDefault();
        this.removeSizeVariation(e);
      });
    }

    setupMediaUploader() {
      // Check if we're on a post edit page
      const postId = $("#post_ID").val();
      if (!postId) return;

      // Add media uploader for image field
      const imageField = $("#menu_item_image");
      if (imageField.length) {
        this.createMediaUploader(imageField, "image");
      }
    }

    createMediaUploader(field, type) {
      const button = $(
        `<button type="button" class="button menu-upload-btn">Select ${type}</button>`
      );
      field.after(button);

      button.on("click", (e) => {
        e.preventDefault();

        const mediaUploader = wp.media({
          title: `Select ${type}`,
          button: {
            text: `Use this ${type}`,
          },
          multiple: false,
        });

        mediaUploader.on("select", () => {
          const attachment = mediaUploader
            .state()
            .get("selection")
            .first()
            .toJSON();
          field.val(attachment.url);
        });

        mediaUploader.open();
      });
    }

    setupTooltips() {
      // Add helpful tooltips to form fields
      const tooltipData = {
        "#menu_item_name": "Enter the name of your menu item",
        "#menu_item_description": "Provide a detailed description of the dish",
        "#menu_item_price": "Enter the price (e.g., $25, 100 ريال)",
        "#menu_item_category": "Select the appropriate category for this item",
        "#menu_item_image": "Add a high-quality image of the dish",
        "#menu_item_video": "YouTube/Vimeo embed URL (optional)",
        "#menu_item_language": "Select the language for this menu item",
      };

      Object.entries(tooltipData).forEach(([selector, tip]) => {
        const field = $(selector);
        if (field.length) {
          field.after(
            `<span class="menu-popup-help" data-tip="${tip}"></span>`
          );
        }
      });
    }

    handleCategoryChange(e) {
      const category = $(e.target).val();
      const categoryTag = $(".menu-item-category-tag");

      if (category) {
        categoryTag.text(category.charAt(0).toUpperCase() + category.slice(1));
        categoryTag.show();
      } else {
        categoryTag.hide();
      }
    }

    handleLanguageChange(e) {
      const language = $(e.target).val();
      const container = $(".menu-popup-container");

      if (language === "ar") {
        container.addClass("arabic");
      } else {
        container.removeClass("arabic");
      }
    }

    handleVideoUrlInput(e) {
      const url = $(e.target).val();
      const field = $(e.target);

      if (!url.trim()) return;

      // Auto-convert YouTube watch URLs to embed URLs
      if (url.includes("youtube.com/watch?v=") || url.includes("youtu.be/")) {
        const videoId = this.extractVideoId(url);
        if (videoId) {
          const embedUrl = `https://www.youtube.com/embed/${videoId}`;
          field.val(embedUrl);
          this.showNotice("YouTube URL converted to embed format", "success");
        }
      }

      // Auto-convert Vimeo URLs
      if (url.includes("vimeo.com/") && !url.includes("player.vimeo.com")) {
        const videoId = this.extractVimeoId(url);
        if (videoId) {
          const embedUrl = `https://player.vimeo.com/video/${videoId}`;
          field.val(embedUrl);
          this.showNotice("Vimeo URL converted to embed format", "success");
        }
      }
    }

    extractVideoId(url) {
      // YouTube watch URL
      const ytWatchMatch = url.match(/[?&]v=([^#\&\?]*)/);
      if (ytWatchMatch) return ytWatchMatch[1];

      // YouTube short URL
      const ytShortMatch = url.match(/youtu\.be\/([^\/\?\&]+)/);
      if (ytShortMatch) return ytShortMatch[1];

      return null;
    }

    extractYouTubeId(url) {
      const match = url.match(/[?&]v=([^#\&\?]*)/);
      return match ? match[1] : null;
    }

    extractVimeoId(url) {
      const match = url.match(/vimeo\.com\/(\d+)/);
      return match ? match[1] : null;
    }

    validateForm(e) {
      const name = $("#menu_item_name").val();
      const category = $("#menu_item_category").val();
      const image = $("#menu_item_image").val();

      let isValid = true;
      let errors = [];

      if (!name.trim()) {
        errors.push("Menu item name is required");
        isValid = false;
      }

      if (!category) {
        errors.push("Please select a category");
        isValid = false;
      }

      if (!image.trim()) {
        errors.push("Please provide an image URL");
        isValid = false;
      }

      if (!isValid) {
        e.preventDefault();
        this.showNotice(errors.join("<br>"), "error");
        return false;
      }

      return true;
    }

    showNotice(message, type = "info") {
      const noticeClass = `menu-popup-notice ${type}`;
      const notice = $(`
                <div class="${noticeClass}">
                    ${message}
                    <a href="#" class="close-notice">&times;</a>
                </div>
            `);

      $(".menu-popup-admin").prepend(notice);

      // Auto-hide after 5 seconds
      setTimeout(() => {
        notice.fadeOut(() => notice.remove());
      }, 5000);

      // Manual close
      notice.on("click", ".close-notice", (e) => {
        e.preventDefault();
        notice.fadeOut(() => notice.remove());
      });
    }

    // Gallery management methods
    addGalleryImage() {
      const container = $("#menu-item-gallery-container");
      const currentItems = container.find(".gallery-image-item");
      const index = currentItems.length;

      const itemId = `gallery_item_${Date.now()}`;

      const newItem = $(`
        <div class="gallery-image-item" data-index="${index}">
          <input type="url" id="${itemId}" name="menu_item_gallery[]" value="" size="40" placeholder="https://example.com/image.jpg" />
          <button type="button" class="button menu-upload-gallery-btn" data-target="${itemId}">Upload</button>
          <button type="button" class="remove-gallery-image button button-small">Remove</button>
          <button type="button" class="move-up-gallery-image button button-small">↑</button>
          <button type="button" class="move-down-gallery-image button button-small">↓</button>
        </div>
      `);

      container.append(newItem);

      // Update all indices after adding
      this.updateGalleryIndices();
    }

    removeGalleryImage(e) {
      const item = $(e.target).closest(".gallery-image-item");
      item.fadeOut(() => {
        item.remove();
        this.updateGalleryIndices();
      });
    }

    moveGalleryImage(e, direction) {
      const item = $(e.target).closest(".gallery-image-item");
      const container = $("#menu-item-gallery-container");
      const currentItems = container.find(".gallery-image-item");

      if (direction === "up") {
        const prevItem = item.prev(".gallery-image-item");
        if (prevItem.length) {
          item.insertBefore(prevItem);
        }
      } else if (direction === "down") {
        const nextItem = item.next(".gallery-image-item");
        if (nextItem.length) {
          item.insertAfter(nextItem);
        }
      }

      this.updateGalleryIndices();
    }

    updateGalleryIndices() {
      const container = $("#menu-item-gallery-container");
      const items = container.find(".gallery-image-item");

      items.each((index, item) => {
        $(item).attr("data-index", index);

        // Update any data attributes if needed
        const urlInput = $(item).find('input[name="menu_item_gallery[]"]');
        const removeBtn = $(item).find(".remove-gallery-image");
        const upBtn = $(item).find(".move-up-gallery-image");
        const downBtn = $(item).find(".move-down-gallery-image");

        // Update button visibility based on position
        if (index === 0) {
          upBtn.prop("disabled", true);
        } else {
          upBtn.prop("disabled", false);
        }

        if (index === items.length - 1) {
          downBtn.prop("disabled", true);
        } else {
          downBtn.prop("disabled", false);
        }
      });
    }

    // Open media uploader for gallery images
    openGalleryMediaUploader(e) {
      const button = $(e.target);
      const targetFieldId = button.data("target");

      const mediaUploader = wp.media({
        title: "Select Gallery Image",
        button: {
          text: "Use this image",
        },
        multiple: false,
      });

      mediaUploader.on("select", () => {
        const attachment = mediaUploader
          .state()
          .get("selection")
          .first()
          .toJSON();

        // Set the URL in the corresponding input field
        $("#" + targetFieldId).val(attachment.url);
      });

      mediaUploader.open();
    }

    // Size & Price Variations methods
    addSizeVariation() {
      const container = $("#menu-item-variations");
      const currentItems = container.find(".variation-item");
      const index = currentItems.length;

      const itemId = `variation_item_${Date.now()}`;

      const newItem = $(`
        <div class="variation-item" data-index="${index}">
          <input type="text" name="menu_item_sizes[${index}][size]" value="" placeholder="Size (e.g., Small, Medium, Large)" size="25" />
          <input type="text" name="menu_item_sizes[${index}][price]" value="" placeholder="Price (e.g., $10.99)" size="15" />
          <button type="button" class="button remove-variation">Remove</button>
        </div>
      `);

      container.append(newItem);
    }

    removeSizeVariation(e) {
      const item = $(e.target).closest(".variation-item");
      item.fadeOut(() => {
        item.remove();
        this.updateVariationIndices();
      });
    }

    updateVariationIndices() {
      const container = $("#menu-item-variations");
      const items = container.find(".variation-item");

      items.each((index, item) => {
        $(item).attr("data-index", index);

        // Update name attributes for proper form submission
        const sizeInput = $(item).find('input[name*="size"]');
        const priceInput = $(item).find('input[name*="price"]');

        if (sizeInput.length) {
          sizeInput.attr("name", `menu_item_sizes[${index}][size]`);
        }
        if (priceInput.length) {
          priceInput.attr("name", `menu_item_sizes[${index}][price]`);
        }
      });
    }

    // Utility function for debouncing
    debounce(func, wait) {
      let timeout;
      return function executedFunction(...args) {
        const later = () => {
          clearTimeout(timeout);
          func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
      };
    }
  }

  // Initialize when DOM is ready
  $(document).ready(() => {
    new MenuPopupAdmin();
  });

  // Categories management functionality (sortable categories removed - conflicts with inline script)

  // Initialize sortable menu items on page load
  $(window).on("load", function () {
    initializeSortableMenuItems();
    $("#category_name").on("input", function () {
      const name = $(this).val();
      if ($("#category_slug").val() === "") {
        const slug = name
          .toLowerCase()
          .replace(/[^a-z0-9\s-]/g, "")
          .replace(/\s+/g, "-")
          .replace(/-+/g, "-")
          .trim("-");
        $("#category_slug").val(slug);
      }
    });

    // Add new category form
    $("#add-category-form").on("submit", function (e) {
      e.preventDefault();
      addNewCategory();
    });

    // Upload category image
    $("#upload_category_image").on("click", function (e) {
      e.preventDefault();
      openMediaUploader("category_image");
    });

    // Delete category
    $(document).on("click", ".delete-category", function (e) {
      e.preventDefault();
      const categoryId = $(this).data("id");
      if (
        confirm(
          "Are you sure you want to delete this category? This action cannot be undone."
        )
      ) {
        deleteCategory(categoryId);
      }
    });

    // Edit category functionality
    $(document).on("click", ".edit-category", function (e) {
      e.preventDefault();
      const categoryId = $(this).data("id");
      window.openEditCategoryForm(categoryId);
    });
  });

  // Menu management event handlers
  $(window).on("load", function () {
    // Auto-generate slug from name for menu
    $("#menu_name").on("input", function () {
      const name = $(this).val();
      if ($("#menu_slug").val() === "") {
        const slug = name
          .toLowerCase()
          .replace(/[^a-z0-9\s-]/g, "")
          .replace(/\s+/g, "-")
          .replace(/-+/g, "-")
          .trim("-");
        $("#menu_slug").val(slug);
      }
    });

    // Add new menu form - prevent duplicate binding
    $("#add-menu-form")
      .off("submit")
      .on("submit", function (e) {
        e.preventDefault();
        addNewMenu();
      });

    // Delete menu
    $(document).on("click", ".delete-menu", function (e) {
      e.preventDefault();
      const menuId = $(this).data("id");
      if (
        confirm(
          "Are you sure you want to delete this menu? This action cannot be undone."
        )
      ) {
        deleteMenu(menuId);
      }
    });

    // Edit menu functionality
    $(document).on("click", ".edit-menu", function (e) {
      e.preventDefault();
      const menuId = $(this).data("id");
      window.openEditMenuForm(menuId);
    });
  });

  // Global category management functions
  function saveCategoryOrder() {
    const categoryOrder = $("#sortable-categories .category-item")
      .map(function () {
        return $(this).data("id");
      })
      .get();

    const nonce = window.menuPopupAdminAjax?.nonce;

    $.ajax({
      url: window.menuPopupAdminAjax?.ajaxurl || ajaxurl,
      type: "POST",
      data: {
        action: "save_category_order",
        category_order: categoryOrder,
        nonce: nonce,
      },
      success: function (response) {
        if (response.success) {
          showAdminNotice(
            response.data || "Category order saved successfully",
            "success"
          );
        } else {
          showAdminNotice(
            response.data || "Failed to save category order",
            "error"
          );
        }
      },
      error: function () {
        showAdminNotice("Failed to save category order", "error");
      },
    });
  }

  function deleteCategory(categoryId) {
    const nonce = window.menuPopupAdminAjax?.nonce;

    $.ajax({
      url: window.menuPopupAdminAjax?.ajaxurl || ajaxurl,
      type: "POST",
      data: {
        action: "delete_category",
        category_id: categoryId,
        nonce: nonce,
      },
      success: function (response) {
        if (response.success) {
          $(`.category-item[data-id="${categoryId}"]`).fadeOut(() => {
            $(this).remove();
            if ($(".category-item").length === 0) {
              location.reload();
            }
          });
          showAdminNotice(
            response.data || "Category deleted successfully",
            "success"
          );
        } else {
          showAdminNotice(
            response.data || "Failed to delete category",
            "error"
          );
        }
      },
      error: function () {
        showAdminNotice("Failed to delete category", "error");
      },
    });
  }

  function addNewCategory() {
    const formData = {
      action: "add_new_category",
      name: $("#category_name").val(),
      slug: $("#category_slug").val(),
      description: $("#category_description").val(),
      image: $("#category_image").val(),
    };

    const nonce = window.menuPopupAdminAjax?.nonce;

    if (!nonce) {
      showAdminNotice(
        "Security token missing. Please refresh the page and try again.",
        "error"
      );
      return;
    }

    formData.nonce = nonce;

    if (!formData.name || !formData.slug) {
      showAdminNotice("Please fill in all required fields", "error");
      return;
    }

    if (!/^[a-z0-9-]+$/.test(formData.slug)) {
      showAdminNotice(
        "Category slug can only contain lowercase letters, numbers, and hyphens",
        "error"
      );
      return;
    }

    const $form = $("#add-category-form");
    const $submitBtn = $("#add-category-btn");
    const originalText = $submitBtn.text();

    $form.addClass("submitting");
    $submitBtn.text("Adding...");

    $.ajax({
      url: window.menuPopupAdminAjax.ajaxurl || ajaxurl,
      type: "POST",
      data: formData,
      success: function (response) {
        if (response.success) {
          const newCategory = response.data.category;
          const categoryHtml = `
                        <div class="category-item" data-id="${newCategory.id}">
                            <div class="category-header">
                                <span class="category-name">${
                                  newCategory.name
                                }</span>
                                <div class="category-actions">
                                    <button class="edit-category button button-small" data-id="${
                                      newCategory.id
                                    }">
                                        Edit
                                    </button>
                                    <button class="delete-category button button-small button-link-delete" data-id="${
                                      newCategory.id
                                    }">
                                        Delete
                                    </button>
                                </div>
                            </div>
                            <div class="category-meta">
                                <span class="category-slug">${
                                  newCategory.slug
                                }</span>
                                ${
                                  newCategory.description
                                    ? `<span class="category-description">${newCategory.description}</span>`
                                    : ""
                                }
                                ${
                                  newCategory.image
                                    ? `<br><img src="${newCategory.image}" style="max-width: 50px; margin-top: 5px;" />`
                                    : ""
                                }
                            </div>
                        </div>
                    `;

          $("#sortable-categories").append(categoryHtml);
          $("#add-category-form")[0].reset();
          $("#category_image_preview").empty();
          $("#sortable-categories").sortable("refresh");

          showAdminNotice(
            response.data.message || "Category added successfully",
            "success"
          );
        } else {
          showAdminNotice(response.data || "Failed to add category", "error");
        }
      },
      error: function (xhr, status, error) {
        showAdminNotice(
          "Failed to add category. Please check the console for details.",
          "error"
        );
      },
      complete: function () {
        $form.removeClass("submitting");
        $submitBtn.text(originalText);
      },
    });
  }

  function showAdminNotice(message, type = "info") {
    const noticeClass = `notice notice-${type} is-dismissible`;
    const notice = $(`
            <div class="${noticeClass}">
                <p>${message}</p>
                <button type="button" class="notice-dismiss">
                    <span class="screen-reader-text">Dismiss this notice.</span>
                </button>
            </div>
        `);

    $(".wrap h1").after(notice);

    setTimeout(() => {
      notice.fadeOut(() => notice.remove());
    }, 5000);

    notice.on("click", ".notice-dismiss", function () {
      notice.fadeOut(() => notice.remove());
    });
  }

  function openMediaUploader(targetField) {
    const mediaUploader = wp.media({
      title: "Select Category Image",
      button: {
        text: "Use this image",
      },
      multiple: false,
    });

    mediaUploader.on("select", function () {
      const attachment = mediaUploader
        .state()
        .get("selection")
        .first()
        .toJSON();
      $("#" + targetField).val(attachment.url);

      $("#category_image_preview").html(
        `<img src="${attachment.url}" style="max-width: 150px; margin-top: 10px;" />`
      );
    });

    mediaUploader.open();
  }

  // Auto-generate slug from name
  $(document).on("input", "#category_name", function () {
    const name = $(this).val();
    const slug = name
      .toLowerCase()
      .replace(/[^a-z0-9\s-]/g, "")
      .replace(/\s+/g, "-")
      .replace(/-+/g, "-")
      .trim("-");

    $("#category_slug").val(slug);
  });

  // Initialize sortable categories and menus
  function initializeSortableCategories() {
    if (
      $("#sortable-categories").length &&
      !$("#sortable-categories").data("sortable-initialized")
    ) {
      $("#sortable-categories")
        .sortable({
          items: ".category-item",
          handle: ".category-header",
          cursor: "move",
          tolerance: "pointer",
          placeholder: "sortable-placeholder",
          update: function (event, ui) {
            const categoryOrder = $(this).sortable("toArray", {
              attribute: "data-id",
            });
            if (categoryOrder.length > 0) {
              const nonce = window.menuPopupAdminAjax?.nonce;
              $.ajax({
                url: window.menuPopupAdminAjax?.ajaxurl || ajaxurl,
                type: "POST",
                data: {
                  action: "save_category_order",
                  category_order: categoryOrder,
                  nonce: nonce,
                },
                success: function (response) {
                  if (response.success) {
                    showAdminNotice(
                      response.data || "Category order saved successfully",
                      "success"
                    );
                  } else {
                    showAdminNotice(
                      response.data || "Failed to save category order",
                      "error"
                    );
                  }
                },
                error: function () {
                  showAdminNotice("Failed to save category order", "error");
                },
              });
            }
          },
        })
        .data("sortable-initialized", true);
    }
  }

  function initializeSortableMenus() {
    if (
      $("#sortable-menus").length &&
      !$("#sortable-menus").data("sortable-initialized")
    ) {
      $("#sortable-menus")
        .sortable({
          items: ".menu-item",
          handle: ".menu-header",
          cursor: "move",
          tolerance: "pointer",
          placeholder: "sortable-placeholder",
          update: function (event, ui) {
            const menuOrder = $(this).sortable("toArray", {
              attribute: "data-id",
            });
            if (menuOrder.length > 0) {
              const nonce = window.menuPopupAdminAjax?.nonce;
              $.ajax({
                url: window.menuPopupAdminAjax?.ajaxurl || ajaxurl,
                type: "POST",
                data: {
                  action: "save_menu_order",
                  menu_order: menuOrder,
                  nonce: nonce,
                },
                success: function (response) {
                  if (response.success) {
                    showAdminNotice(
                      response.data || "Menu order saved successfully",
                      "success"
                    );
                  } else {
                    showAdminNotice(
                      response.data || "Failed to save menu order",
                      "error"
                    );
                  }
                },
                error: function () {
                  showAdminNotice("Failed to save menu order", "error");
                },
              });
            }
          },
        })
        .data("sortable-initialized", true);
    }
  }

  // Initialize sortables after document ready
  $(document).ready(function () {
    initializeSortableCategories();
    initializeSortableMenus();
  });

  // Add new category form - prevent duplicate binding
  $("#add-category-form")
    .off("submit")
    .on("submit", function (e) {
      e.preventDefault();
      addNewCategory();
    });

  // Upload category image
  $("#upload_category_image").on("click", function (e) {
    e.preventDefault();
    openMediaUploader("category_image");
  });

  // Delete category
  $(document).on("click", ".delete-category", function (e) {
    e.preventDefault();
    const categoryId = $(this).data("id");
    if (
      confirm(
        "Are you sure you want to delete this category? This action cannot be undone."
      )
    ) {
      deleteCategory(categoryId);
    }
  });

  // Menu management functions - equivalent to category functions
  function saveMenuOrder() {
    const menuOrder = $("#sortable-menus .menu-item")
      .map(function () {
        return $(this).data("id");
      })
      .get();

    const nonce = window.menuPopupAdminAjax?.nonce;

    $.ajax({
      url: window.menuPopupAdminAjax?.ajaxurl || ajaxurl,
      type: "POST",
      data: {
        action: "save_menu_order",
        menu_order: menuOrder,
        nonce: nonce,
      },
      success: function (response) {
        if (response.success) {
          showAdminNotice(
            response.data || "Menu order saved successfully",
            "success"
          );
        } else {
          showAdminNotice(
            response.data || "Failed to save menu order",
            "error"
          );
        }
      },
      error: function () {
        showAdminNotice("Failed to save menu order", "error");
      },
    });
  }

  function deleteMenu(menuId) {
    const nonce = window.menuPopupAdminAjax?.nonce;

    $.ajax({
      url: window.menuPopupAdminAjax?.ajaxurl || ajaxurl,
      type: "POST",
      data: {
        action: "delete_menu",
        menu_id: menuId,
        nonce: nonce,
      },
      success: function (response) {
        if (response.success) {
          $(`.menu-item[data-id="${menuId}"]`).fadeOut(() => {
            $(this).remove();
            if ($(".menu-item").length === 0) {
              location.reload();
            }
          });
          showAdminNotice(
            response.data || "Menu deleted successfully",
            "success"
          );
        } else {
          showAdminNotice(response.data || "Failed to delete menu", "error");
        }
      },
      error: function () {
        showAdminNotice("Failed to delete menu", "error");
      },
    });
  }

  function addNewMenu() {
    const categories = [];

    // Collect selected categories
    $('input[name="menu_categories[]"]:checked').each(function () {
      categories.push($(this).val());
    });

    const formData = {
      action: "add_new_menu",
      name: $("#menu_name").val(),
      slug: $("#menu_slug").val(),
      description: $("#menu_description").val(),
      categories: categories,
    };

    const nonce = window.menuPopupAdminAjax?.nonce;

    if (!nonce) {
      showAdminNotice(
        "Security token missing. Please refresh the page and try again.",
        "error"
      );
      return;
    }

    formData.nonce = nonce;

    if (!formData.name || !formData.slug) {
      showAdminNotice("Please fill in all required fields", "error");
      return;
    }

    if (!/^[a-z0-9-]+$/.test(formData.slug)) {
      showAdminNotice(
        "Menu slug can only contain lowercase letters, numbers, and hyphens",
        "error"
      );
      return;
    }

    const $form = $("#add-menu-form");
    const $submitBtn = $("#add-menu-btn");
    const originalText = $submitBtn.text();

    $form.addClass("submitting");
    $submitBtn.text("Adding...");

    $.ajax({
      url: window.menuPopupAdminAjax.ajaxurl || ajaxurl,
      type: "POST",
      data: formData,
      success: function (response) {
        if (response.success) {
          const newMenu = response.data.menu;

          const categoriesHtml =
            newMenu.categories && newMenu.categories.length > 0
              ? newMenu.categories
                  .map((catSlug) => {
                    // Simple category name mapping - you might want to make this more sophisticated
                    return `<span class="category-tag">${catSlug}</span>`;
                  })
                  .join("")
              : "";

          const menuHtml = `
                        <div class="menu-item" data-id="${newMenu.id}">
                            <div class="menu-header">
                                <span class="menu-name">${newMenu.name}</span>
                                <div class="menu-actions">
                                    <button class="edit-menu button button-small" data-id="${
                                      newMenu.id
                                    }">
                                        Edit
                                    </button>
                                    <button class="delete-menu button button-small button-link-delete" data-id="${
                                      newMenu.id
                                    }">
                                        Delete
                                    </button>
                                </div>
                            </div>
                            <div class="menu-meta">
                                <span class="menu-slug">${newMenu.slug}</span>
                                ${
                                  newMenu.description
                                    ? `<span class="menu-description">${newMenu.description}</span>`
                                    : ""
                                }
                                <div class="menu-categories">
                                  <strong>Categories: </strong>${
                                    categoriesHtml ||
                                    '<span class="category-tag">None</span>'
                                  }
                                </div>
                            </div>
                        </div>
                    `;

          $("#sortable-menus").append(menuHtml);
          $("#add-menu-form")[0].reset();
          $("#sortable-menus").sortable("refresh");

          showAdminNotice(
            response.data.message || "Menu added successfully",
            "success"
          );
        } else {
          showAdminNotice(response.data || "Failed to add menu", "error");
        }
      },
      error: function (xhr, status, error) {
        showAdminNotice(
          "Failed to add menu. Please check the console for details.",
          "error"
        );
      },
      complete: function () {
        $form.removeClass("submitting");
        $submitBtn.text(originalText);
      },
    });
  }

  // Global menu edit functions (defined outside jQuery wrapper like category ones)
  window.openEditMenuForm = function (menuId) {
    const menuItems = jQuery("[data-id='" + menuId + "']");
    if (menuItems.length === 0) return;

    const menuItem = menuItems.first();
    const menuName = menuItem.find(".menu-name").text().trim();
    const menuSlug = menuItem.find(".menu-slug").text().trim();
    const menuDescription = menuItem.find(".menu-description").text().trim();

    // Get current categories for this menu from the menu data
    let currentCategories = [];
    let currentBranches = [];
    if (window.menuPopupMenus && Array.isArray(window.menuPopupMenus)) {
      const currentMenu = window.menuPopupMenus.find(
        (menu) => menu.id === menuId
      );
      if (currentMenu && currentMenu.categories) {
        currentCategories = currentMenu.categories;
      }
      if (currentMenu && currentMenu.branches) {
        currentBranches = currentMenu.branches;
      }
    } else {
      // Fallback: try to get from display tags (old method)
      const categoriesContainer = menuItem.find(
        ".menu-categories .category-tag"
      );
      categoriesContainer.each(function () {
        const tagText = jQuery(this).text().trim();
        if (tagText && tagText !== "None") {
          // This is a simplified mapping - you'd want to map back to slugs
          currentCategories.push(tagText.toLowerCase().replace(/\s+/g, "-"));
        }
      });
    }

    // Check if edit form already exists for this menu
    if (jQuery("#edit-menu-form-" + menuId).length > 0) {
      window.closeEditMenuForm(menuId);
      return;
    }

    // Close any other edit forms first
    jQuery(".edit-menu-form").remove();

    // Create inline edit form
    const editForm =
      jQuery(`<div id="edit-menu-form-${menuId}" class="edit-menu-form" style="margin-top: 10px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
        <h4 style="margin: 0 0 10px 0; color: #23282d;">Edit Menu</h4>
        <table class="form-table">
          <tr>
            <th><label for="edit_menu_name_${menuId}">Name</label></th>
            <td><input type="text" id="edit_menu_name_${menuId}" value="${menuName}" size="50" /></td>
          </tr>
          <tr>
            <th><label for="edit_menu_slug_${menuId}">Slug</label></th>
            <td><input type="text" id="edit_menu_slug_${menuId}" value="${menuSlug}" size="50" /></td>
          </tr>
          <tr>
            <th><label for="edit_menu_description_${menuId}">Description</label></th>
            <td><textarea id="edit_menu_description_${menuId}" rows="3" cols="50">${menuDescription}</textarea></td>
          </tr>
          <tr>
            <th><label for="edit_menu_categories_${menuId}">Categories</label></th>
            <td>
              <div class="menu-categories-edit" id="edit_menu_categories_container_${menuId}">
                <!-- Categories will be populated by server-side data -->
              </div>
            </td>
          </tr>
          <tr>
            <th><label for="edit_menu_branches_${menuId}">Branches</label></th>
            <td>
              <div class="menu-branches-edit" id="edit_menu_branches_container_${menuId}">
                <!-- Branches will be populated by server-side data -->
              </div>
            </td>
          </tr>
        </table>
        <p class="submit">
          <button type="button" class="button button-primary" onclick="window.updateMenu('${menuId}')" id="update-menu-btn-${menuId}">Update Menu</button>
          <button type="button" class="button" onclick="window.closeEditMenuForm('${menuId}')">Cancel</button>
        </p>
      </div>`);

    // Populate categories checkboxes dynamically from available categories
    let categoriesHtml = "";
    if (
      window.menuPopupCategories &&
      Array.isArray(window.menuPopupCategories)
    ) {
      window.menuPopupCategories.forEach((category) => {
        const isChecked = currentCategories.includes(category.slug)
          ? "checked"
          : "";
        categoriesHtml += `
          <label class="category-checkbox">
            <input type="checkbox" name="edit_menu_categories_${menuId}[]" value="${category.slug}" ${isChecked} />
            ${category.name}
          </label>
        `;
      });
    } else {
      // Fallback to hardcoded categories if data is not available
      categoriesHtml = `
        <label class="category-checkbox">
          <input type="checkbox" name="edit_menu_categories_${menuId}[]" value="starters" ${
        currentCategories.includes("starters") ? "checked" : ""
      } />
          Starters
        </label>
        <label class="category-checkbox">
          <input type="checkbox" name="edit_menu_categories_${menuId}[]" value="main-courses" ${
        currentCategories.includes("main-courses") ? "checked" : ""
      } />
          Main Courses
        </label>
        <label class="category-checkbox">
          <input type="checkbox" name="edit_menu_categories_${menuId}[]" value="deserts" ${
        currentCategories.includes("deserts") ? "checked" : ""
      } />
          Desserts
        </label>
        <label class="category-checkbox">
          <input type="checkbox" name="edit_menu_categories_${menuId}[]" value="beverages" ${
        currentCategories.includes("beverages") ? "checked" : ""
      } />
          Beverages
        </label>
      `;
    }
    editForm
      .find(`#edit_menu_categories_container_${menuId}`)
      .html(categoriesHtml);

    // Populate branches checkboxes dynamically from available branches
    let branchesHtml = "";
    if (
      window.menuPopupBranches &&
      Array.isArray(window.menuPopupBranches) &&
      window.menuPopupBranches.length > 0
    ) {
      window.menuPopupBranches.forEach((branch) => {
        const isChecked = currentBranches.includes(branch.id)
          ? "checked"
          : "";
        branchesHtml += `
          <label class="branch-checkbox">
            <input type="checkbox" name="edit_menu_branches_${menuId}[]" value="${branch.id}" ${isChecked} />
            ${branch.name}
          </label>
        `;
      });
    } else {
      branchesHtml = '<p class="description">No branches available. Create branches first.</p>';
    }
    editForm
      .find(`#edit_menu_branches_container_${menuId}`)
      .html(branchesHtml);

    // Insert edit form after the menu item
    menuItem.after(editForm);
    menuItem.hide();

    // Auto-generate slug from name when editing
    jQuery(document).on("input", "#edit_menu_name_" + menuId, function () {
      const name = jQuery(this).val();
      const slug = name
        .toLowerCase()
        .replace(/[^a-z0-9\s-]/g, "")
        .replace(/\s+/g, "-")
        .replace(/-+/g, "-")
        .trim("-");
      jQuery("#edit_menu_slug_" + menuId).val(slug);
    });
  };

  window.closeEditMenuForm = function (menuId) {
    jQuery("#edit-menu-form-" + menuId).remove();
    jQuery("[data-id='" + menuId + "']").show();
  };

  window.updateMenu = function (menuId) {
    const name = jQuery("#edit_menu_name_" + menuId).val();
    const slug = jQuery("#edit_menu_slug_" + menuId).val();
    const description = jQuery("#edit_menu_description_" + menuId).val();
    const categories = jQuery(
      `input[name="edit_menu_categories_${menuId}[]"]:checked`
    )
      .map(function () {
        return this.value;
      })
      .get();

    const branches = jQuery(
      `input[name="edit_menu_branches_${menuId}[]"]:checked`
    )
      .map(function () {
        return parseInt(this.value);
      })
      .get();

    const nonce = window.menuPopupAdminAjax?.nonce;

    if (!name || !slug) {
      showAdminNotice("Please fill in all required fields", "error");
      return;
    }

    if (!/^[a-z0-9-]+$/.test(slug)) {
      showAdminNotice(
        "Menu slug can only contain lowercase letters, numbers, and hyphens",
        "error"
      );
      return;
    }

    const updateBtn = jQuery("#update-menu-btn-" + menuId);
    const originalText = updateBtn.text();
    updateBtn.text("Updating...").prop("disabled", true);

    jQuery.ajax({
      url: window.menuPopupAdminAjax.ajaxurl || ajaxurl,
      type: "POST",
      data: {
        action: "update_menu",
        menu_id: menuId,
        name: name,
        slug: slug,
        description: description,
        categories: categories,
        branches: branches,
        nonce: nonce,
      },
      success: function (response) {
        if (response.success) {
          const menuItem = jQuery("[data-id='" + menuId + "']");
          menuItem.find(".menu-name").text(name);
          menuItem.find(".menu-slug").text(slug);

          const descSpan = menuItem.find(".menu-description");
          if (description.trim()) {
            if (descSpan.length) descSpan.text(description);
            else
              menuItem
                .find(".menu-meta")
                .append(`<span class="menu-description">${description}</span>`);
          } else descSpan.remove();

          // Update categories display
          const categoriesContainer = menuItem.find(".menu-categories");
          if (categories.length > 0) {
            const categoryNames = categories.map((cat) =>
              cat.replace(/-/g, " ").replace(/\b\w/g, (l) => l.toUpperCase())
            );
            const categoriesHtml =
              "<strong>Categories: </strong>" +
              categoryNames
                .map((name) => `<span class="category-tag">${name}</span>`)
                .join(" ");
            categoriesContainer.html(categoriesHtml);
          } else {
            categoriesContainer.html(
              '<strong>Categories: </strong><span class="category-tag">None</span>'
            );
          }

          window.closeEditMenuForm(menuId);
          showAdminNotice("Menu updated successfully", "success");
        } else {
          showAdminNotice(response.data || "Failed to update menu", "error");
        }
      },
      error: function () {
        showAdminNotice("Failed to update menu", "error");
      },
      complete: function () {
        updateBtn.text(originalText).prop("disabled", false);
      },
    });
  };

  // Initialize sortable menus (removed - conflicts with inline script)

  // Make functions globally available
  window.saveCategoryOrder = saveCategoryOrder;
  window.deleteCategory = deleteCategory;
  window.addNewCategory = addNewCategory;
  window.showAdminNotice = showAdminNotice;
  window.saveMenuOrder = saveMenuOrder;
  window.deleteMenu = deleteMenu;
  window.addNewMenu = addNewMenu;

  // Add nonce for AJAX requests if not already present
  if (typeof window.menuPopupAdminNonce === "undefined") {
    // Try multiple sources for nonce
    window.menuPopupAdminNonce = window.menuPopupAdminAjax?.nonce || "";
  }
  // Global functions
  function initializeSortableMenuItems() {
    // Check if we're on the menu_item edit page and have the table list
    if (
      $("#the-list .sortable-handle").length &&
      $("#post-type").val() === "menu_item"
    ) {
      $("#the-list").sortable({
        items: "tr",
        cursor: "move",
        handle: ".sortable-handle",
        placeholder: "sortable-placeholder",
        opacity: 0.8,
        tolerance: "pointer",
        helper: function (e, ui) {
          ui.children().each(function () {
            $(this).width($(this).width());
          });
          return ui;
        },
        update: function (event, ui) {
          var itemIds = [];
          $("#the-list tr").each(function (index) {
            var itemId = $(this).attr("id");
            if (itemId && itemId.startsWith("post-")) {
              itemId = itemId.replace("post-", "");
              itemIds.push(parseInt(itemId));
            }
          });

          if (itemIds.length > 0) {
            // Send AJAX request to update order
            $.ajax({
              url: window.menuPopupAdminAjax.ajaxurl || ajaxurl,
              method: "POST",
              data: {
                action: "update_menu_item_order",
                nonce: window.menuPopupAdminAjax.nonce,
                item_ids: itemIds,
              },
              success: function (response) {
                if (response.success) {
                  // Remove any existing notices
                  $(".notice").remove();

                  // Show success message
                  var notice = $(
                    '<div class="notice notice-success is-dismissible"><p>Menu item order updated successfully.</p></div>'
                  );
                  $(".wrap h1").after(notice);

                  // Make it dismissible
                  setTimeout(function () {
                    notice.fadeOut(function () {
                      notice.remove();
                    });
                  }, 3000);
                } else {
                  alert(
                    "Error updating order: " +
                      (response.data || "Unknown error")
                  );
                }
              },
              error: function () {
                alert("Error updating order. Please try again.");
              },
            });
          }
        },
      });
    }
  }
})(jQuery);
