/**
 * Menu Pop-up Frontend JavaScript - Performance Optimized
 * Handles category filtering and video popup functionality
 */

(function ($) {
  "use strict";

  try {
    class MenuPopup {
      constructor() {
        this.container = $(".menu-popup-container");
        this.categoryBtns = $(".category-btn");
        this.menuItems = $(".menu-item");
        this.videoModals = $(".video-modal");
        this.body = $("body");
        this.animationSpeed = parseInt(menuPopupAjax.animationSpeed) || 300;
        this.enableVideos = menuPopupAjax.enableVideos === "1";
        this.enableVideoAutoplay = menuPopupAjax.enableVideoAutoplay === "1";
        this.currentGalleryIndex = 0;
        this.currentGalleryImages = [];
        this.timeouts = {}; // Track timeouts
        this.currentCategory = 'all'; // Track current category for show more
        this.initialLimit = 6; // Default initial limit
        this.fullLimit = 11; // Default full limit

        // Customer information storage for order flow
        this.customerInfo = {
          name: "",
          phone: "",
          email: "",
          address: "",
          delivery_zone: "",
          special_instructions: "",
          order_type: "pickup",
        };

        this.init();
      }

      init() {
        this.bindEvents();
        this.setupAnimations();
        this.handleInitialCategory();
        this.initCart();
        this.initOrdering();
        this.initLayoutSwitching();
        this.initSpeechToggle();
        
        // Initialize limits from show more button if exists
        const showMoreBtn = $(".show-more-btn");
        if (showMoreBtn.length > 0) {
          this.initialLimit = parseInt(showMoreBtn.data("initial-limit")) || 6;
          this.fullLimit = parseInt(showMoreBtn.data("full-limit")) || -1;
        }
      }

      bindEvents() {
        // Search functionality
        const searchInput = $("#menu-search-input");
        const searchClear = $("#search-clear");

        if (searchInput.length > 0) {
          // Debounced search input handler
          searchInput.on(
            "input",
            this.debounce((e) => {
              const searchTerm = $(e.target).val().trim();
              this.performSearch(searchTerm);
            }, 300)
          );

          // Clear search button
          searchClear.on("click", () => {
            searchInput.val("");
            this.performSearch("");
          });

          // Show/hide clear button based on input
          searchInput.on("input", (e) => {
            const hasValue = $(e.target).val().trim().length > 0;
            searchClear.toggle(hasValue);
          });
        }

        // Category filtering
        this.categoryBtns.on("click", (e) => {
          e.preventDefault();
          const category = $(e.currentTarget).data("category");
          this.filterByCategory(category);
        });

        // Video popup - use event delegation to work with AJAX-loaded content
        this.container.on("click", ".menu-item", (e) => {
          // Check if videos are enabled first
          if (!this.enableVideos) {
            return;
          }

          // Check if the click came from an action button (add to cart or order)
          if (
            $(e.target).closest(
              ".add-to-cart-btn, .order-whatsapp-btn, .size-btn"
            ).length > 0
          ) {
            return;
          }

          e.preventDefault();
          e.stopPropagation();

          const item = $(e.currentTarget);
          const videoUrl = item.data("video");

          if (!videoUrl || videoUrl.trim() === "") {
            return;
          }

          // Get the actual displayed content (which may be Arabic or English based on fallback logic)
          const title = item.find(".menu-item-name").text();
          const description = item.find(".menu-item-description").text();

          const galleryData = item.data("gallery");

          // Get current price - check for active size tag first, then fall back to data-price
          let currentPrice = "";
          const activeTag = item.find(".size-tag.active");
          if (activeTag.length > 0) {
            currentPrice = activeTag.data("price");
          } else {
            currentPrice = item.data("price") || "";
          }

          // Get size variations data
          const sizesData = item.data("sizes");
          let variations = [];
          const sizesStr = String(sizesData || "").trim();
          if (sizesStr !== "") {
            try {
              variations = JSON.parse(sizesStr);
              if (!Array.isArray(variations)) {
                variations = [];
              }
            } catch (e) {
              variations = [];
            }
          }

          // Parse gallery data (jQuery.data() may auto-parse JSON)
          let galleryImages = [];
          if (galleryData) {
            try {
              // jQuery.data() auto-parses JSON strings to actual Arrays/Objects
              if (Array.isArray(galleryData)) {
                galleryImages = galleryData;
              } else if (typeof galleryData === "string") {
                // Fallback: manual parsing if jQuery didn't auto-parse
                galleryImages = JSON.parse(galleryData);
              } else {
                galleryImages = [];
              }

              // Filter out empty/malformed URLs
              galleryImages = Array.isArray(galleryImages)
                ? galleryImages.filter((img) => img && img.trim())
                : [];
            } catch (e) {
              galleryImages = [];
            }
          }

          // Process URL if needed
          const processedUrl =
            videoUrl.includes("m.youtube.com") ||
            videoUrl.includes("youtube.com/watch")
              ? this.processVideoUrl(videoUrl)
              : videoUrl;

          this.openGlobalVideo(
            processedUrl,
            title,
            description,
            galleryImages,
            currentPrice,
            variations,
            item.attr("id") || item.data("itemId") || item.find(".add-to-cart-btn").first().attr("data-item-id") || null
          );
        });

        // Close global video modal
        $(document).on("click", ".video-modal-close", (e) => {
          e.preventDefault();
          this.closeGlobalVideo();
        });
        // Change branch functionality
        $("#changeBranch").on("click", function (e) {
          e.preventDefault();
          loadAllBranchesForChange();
          $("#branchChangeModal").show();
        });

        $(".branch-change-close").on("click", function () {
          $("#branchChangeModal").hide();
        });
        // Handle branch selection in change modal - simple redirect (like initial selection)
        $(document).on("click", ".branch-change-card", function (e) {
          e.preventDefault();
          e.stopPropagation();
          
          const newBranchId = $(this).data("branch-id");
          
          if (newBranchId) {
            // Store branch in localStorage for faster subsequent visits
            localStorage.setItem('menuPopupBranch', newBranchId);
            
            // Update URL first
            const url = new URL(window.location.href);
            url.searchParams.set('branch', newBranchId);
            window.history.pushState({}, '', url.toString());
            
            // Then reload - this ensures all PHP renders correctly
            window.location.reload();
          }
        });

        // Handle "Select Different Branch" button when no menu for branch
        $(document).on("click", "#selectDifferentBranch", function (e) {
          e.preventDefault();
          
          // Remove branch from URL
          const url = new URL(window.location.href);
          url.searchParams.delete('branch');
          window.history.pushState({}, '', url.toString());
          
          // Reload to show branch selection
          window.location.reload();
        });

        function loadAllBranchesForChange() {
          $.ajax({
            url: menuPopupAjax.ajaxurl,
            type: "POST",
            data: {
              action: "get_branches_by_city",
              city_slug: "", // Empty gets all branches
              nonce: menuPopupAjax.nonce,
            },
            success: function (response) {
              if (response.success) {
                $("#branchChangeGrid").html(response.data);
                $("#branchChangeGrid .branch-card")
                  .addClass("branch-change-card")
                  .removeClass("branch-card");
              }
            },
          });
        }

        // Close video on background click
        $(document).on("click", ".video-modal-overlay", (e) => {
          if ($(e.target).hasClass("video-modal-overlay")) {
            this.closeGlobalVideo();
          }
        });

        // Close video on escape key
        $(document).on("keydown", (e) => {
          if (e.keyCode === 27) {
            this.closeAllVideos();
          }
        });

        // Variation selection in modal
        $(document).on("click", ".modal-size-tag", (e) => {
          const btn = $(e.currentTarget);
          const modal = btn.closest("#globalVideoModal");
          const variationsContainer = modal.find(".video-modal-variations");
          const priceDisplay = modal.find(".video-modal-price");

          // Remove active class from all buttons
          variationsContainer.find(".modal-size-tag").removeClass("active");

          // Add active class to clicked button
          btn.addClass("active");

          // Update price display
          const selectedPrice = btn.data("price");
          if (priceDisplay.length > 0 && selectedPrice) {
            priceDisplay
              .text(menuPopupAjax.currencySymbol + selectedPrice)
              .show();
          }

          // Store selected price for ordering
          modal.data("selectedPrice", selectedPrice);
          modal.data("selectedSize", btn.data("size"));
        });

        // Size selection on menu items (frontend grid/list)
        $(document).on("click", ".size-btn", (e) => {
          e.stopPropagation(); // Prevent triggering menu item click

          const btn = $(e.currentTarget);
          const menuItem = btn.closest(".menu-item");
          const sizeButtons = menuItem.find(".size-btn");

          // Remove active class from all size buttons in this item
          sizeButtons.removeClass("active");

          // Add active class to clicked button
          btn.addClass("active");

          // Get selected size and price
          const selectedSize = btn.data("size");
          const selectedPrice = btn.data("price");

          // Update add to cart button data attributes
          const addToCartBtn = menuItem.find(".add-to-cart-btn");
          if (addToCartBtn.length > 0) {
            addToCartBtn.data("selected-size", selectedSize);
            addToCartBtn.data("selected-price", selectedPrice);
          }

          // Update order whatsapp button data attributes
          const orderWhatsAppBtn = menuItem.find(".order-whatsapp-btn");
          if (orderWhatsAppBtn.length > 0) {
            orderWhatsAppBtn.data("selected-size", selectedSize);
            orderWhatsAppBtn.data("selected-price", selectedPrice);
          }

          // Update item price display if showing variations
          const itemPriceElement = menuItem.find(".menu-item-price");
          if (itemPriceElement.length > 0 && selectedPrice) {
            itemPriceElement.text(menuPopupAjax.currencySymbol + selectedPrice);
          }

          // Show ordering buttons after size selection (only if there are multiple variations)
          const menuItemActions = menuItem.find(".menu-item-actions");
          if (menuItemActions.hasClass("has-variations")) {
            menuItemActions.find(".requires-size-selection").show();
          }
        });

        // Modal order button events (Add to Cart and WhatsApp)
        $(document).on("click", ".video-add-to-cart-btn", (e) => {
          e.preventDefault();
          e.stopPropagation();

          const modal = $("#globalVideoModal");
          const selectedPrice =
            modal.data("selectedPrice") || modal.data("price") || "";
          const selectedSize = modal.data("selectedSize") || "";
          const itemName = modal.find(".video-modal-title").text();

          // Generate unique item ID based on modal content and selected variation
          const itemBaseId =
            modal.data("itemId") || Math.random().toString(36).substr(2, 9);
          const itemId = selectedSize
            ? `${itemBaseId}_${selectedSize}`
            : itemBaseId;
          const displayName = selectedSize
            ? `${itemName} (${selectedSize})`
            : itemName;

          if (selectedPrice !== undefined && selectedPrice !== null) {
            // Parse selectedPrice to ensure it's a clean number
            let cleanSelectedPrice = selectedPrice;
            if (typeof cleanSelectedPrice === "string") {
              const priceMatch = cleanSelectedPrice.replace(/[^\d.-]/g, "");
              cleanSelectedPrice = parseFloat(priceMatch) || 0;
            } else {
              cleanSelectedPrice = parseFloat(cleanSelectedPrice) || 0;
            }

            window.menuPopup.addToCart(
              itemId,
              displayName,
              cleanSelectedPrice,
              itemBaseId,
              selectedSize || ""
            );
            window.menuPopup.closeGlobalVideo(); // Close modal after adding to cart
          }
        });

        $(document).on("click", ".video-order-whatsapp-btn", (e) => {
          e.preventDefault();
          e.stopPropagation();

          const modal = $("#globalVideoModal");
          const selectedPrice =
            modal.data("selectedPrice") || modal.data("price") || "";
          const selectedSize = modal.data("selectedSize") || "";
          const itemName = modal.find(".video-modal-title").text();
          const displayName = selectedSize
            ? `${itemName} (${selectedSize})`
            : itemName;

          if (selectedPrice) {
            window.menuPopup.orderViaWhatsApp(displayName, selectedPrice);
            window.menuPopup.closeGlobalVideo(); // Close modal after ordering
          }
        });

        // Show more functionality - bind with higher specificity and prevent bubbling
        $(document).on("click.showMore", ".show-more-btn", (e) => {
          e.preventDefault();
          e.stopPropagation();
          e.stopImmediatePropagation();

          const btn = $(e.currentTarget);
          const initialLimit = parseInt(btn.data("initial-limit")) || 6;
          const fullLimit = parseInt(btn.data("full-limit")) || -1;
          const menu = btn.data("menu") || "all";
          // Use current category from state instead of button data
          const category = this.currentCategory || btn.data("category") || "all";
          const allFilter = btn.data("all-filter") || "true";

          // Add loading state
          btn.prop("disabled", true).text("Loading...");

          this.loadMoreItems(
            btn,
            initialLimit,
            fullLimit,
            menu,
            category,
            allFilter
          );

          return false;
        });

        // Language switcher functionality
        const languageBtns = $(".language-btn");
        languageBtns.on("click", (e) => {
          e.preventDefault();
          const lang = $(e.currentTarget)
            .attr("href")
            .match(/[?&]lang=([^&]*)/)?.[1];
          if (lang) {
            // Set cookie for language preference
            const expirationDate = new Date();
            expirationDate.setFullYear(expirationDate.getFullYear() + 1); // Cookie lasts 1 year
            document.cookie = `menu_popup_lang=${lang}; expires=${expirationDate.toUTCString()}; path=/`;

            // Reload the page to apply the language change
            window.location.reload();
          }
        });

        // Handle window resize for responsive animations
        $(window).on(
          "resize",
          this.debounce(() => {
            this.setupAnimations();
          }, 250)
        );
      }

      filterByCategory(category) {
        const container = this.container;
        
        // Update current category state
        this.currentCategory = category;
        
        // Update active button
        this.categoryBtns.removeClass("active");
        $(`.category-btn[data-category="${category}"]`).addClass("active");

        // Show loading state
        container.addClass("loading");

        // Always use AJAX to fetch items from server with pagination
        // This ensures consistent behavior for all categories including "all"
        $.ajax({
          url: menuPopupAjax.ajaxurl,
          type: "POST",
          data: {
            action: "filter_menu_items",
            category: category,
            menu: container.data("menu") || "all",
            offset: 0,
            limit: this.initialLimit || 6, // Start with initial limit (6 items)
            branch: new URLSearchParams(window.location.search).get("branch") || "",
            nonce: menuPopupAjax.nonce,
          },
          success: (response) => {
            if (response.success) {
              // Replace menu grid content
              $(".menu-grid").html(response.data);

              // Get current layout and apply it to the new content
              const currentLayout = container.data("layout") || "grid";

              // Apply layout-specific structure for masonry
              if (currentLayout === "masonry") {
                const items = $(".menu-grid .menu-item");
                const columns = [[], [], []];

                items.each(function (index) {
                  const columnIndex = index % 3;
                  columns[columnIndex].push($(this));
                });

                $(".menu-grid").empty();
                columns.forEach(function (columnItems) {
                  const columnDiv = $("<div class='menu-column'></div>");
                  columnItems.forEach(function (item) {
                    columnDiv.append(item);
                  });
                  $(".menu-grid").append(columnDiv);
                });
              }

              // Apply current layout class
              container.removeClass("layout-grid layout-list layout-masonry");
              container.addClass(`layout-${currentLayout}`);

              // Reinitialize animations
              $(".menu-item").each(function (index) {
                let delay = `${index * 0.1}s`;
                if (currentLayout === "list") {
                  delay = `${index * 0.05}s`;
                } else if (currentLayout === "masonry") {
                  delay = `${Math.floor(index / 3) * 0.1}s`;
                }
                $(this).css("animation-delay", delay);
              });

              // Reinitialize video popup events for new content
              if (this.enableVideos) {
                $(".menu-item").off("click").on("click", (e) => {
                  if ($(e.target).closest(".add-to-cart-btn, .order-whatsapp-btn, .size-btn").length > 0) {
                    return;
                  }
                  e.preventDefault();
                  e.stopPropagation();
                  
                  const item = $(e.currentTarget);
                  const videoUrl = item.data("video");
                  const title = item.find(".menu-item-name").text();
                  const description = item.find(".menu-item-description").text();

                  if (videoUrl && videoUrl.trim() !== "") {
                    let processedUrl = videoUrl;
                    if (videoUrl.includes("m.youtube.com") || videoUrl.includes("youtube.com/watch")) {
                      processedUrl = this.processVideoUrl(videoUrl);
                    }

                    const galleryData = item.data("gallery");
                    let galleryImages = [];
                    if (galleryData) {
                      try {
                        if (Array.isArray(galleryData)) {
                          galleryImages = galleryData;
                        } else if (typeof galleryData === "string") {
                          galleryImages = JSON.parse(galleryData);
                        }
                        galleryImages = Array.isArray(galleryImages) ? galleryImages.filter((img) => img && img.trim()) : [];
                      } catch (e) {
                        galleryImages = [];
                      }
                    }

                    const currentPrice = item.data("price") || "";
                    const variations = item.data("sizes") || [];

                    this.openGlobalVideo(processedUrl, title, description, galleryImages, currentPrice, variations, item.attr("id") || item.data("itemId") || item.find(".add-to-cart-btn").first().attr("data-item-id") || null);
                  }
                });
              }

              // Update show more button based on total items loaded
              const totalItems = $(".menu-item").length;
              if (totalItems > this.initialLimit) {
                this.renderShowMoreButton(category, totalItems, this.initialLimit);
              } else {
                this.removeShowMoreButton();
              }
            }
          },
          error: () => {
            console.error("Category filter request failed");
          },
          complete: () => {
            container.removeClass("loading");
          },
        });
      }

      updateShowMoreButton(category) {
        const showMoreBtn = $(".show-more-btn");
        if (showMoreBtn.length > 0) {
          showMoreBtn.attr("data-category", category);
        }
      }

      showAllItems() {
        // Use requestAnimationFrame for better performance
        requestAnimationFrame(() => {
          this.menuItems = $(".menu-item"); // Re-fetch menu items
          this.menuItems.each((index, item) => {
            const $item = $(item);
            // Use CSS transitions instead of JavaScript animation delays
            $item
              .css({
                "animation-delay": `${index * 0.1}s`,
                opacity: "1",
                transform: "translateY(0)",
              })
              .show();
          });
          
          // Re-render show more button for all items
          this.renderShowMoreButton("all");
        });
      }

      showCategoryItems(category) {
        // Use requestAnimationFrame for better performance
        requestAnimationFrame(() => {
          this.menuItems = $(".menu-item"); // Re-fetch menu items
          const categoryItems = this.menuItems.filter(function() {
            return $(this).data("category") === category;
          });
          
          // Hide all items first
          this.menuItems.hide();
          
          // Show only initialLimit items for this category
          const limit = this.initialLimit || 6;
          categoryItems.each((index, item) => {
            const $item = $(item);
            if (index < limit) {
              $item
                .css({
                  "animation-delay": `${index * 0.1}s`,
                  opacity: "1",
                  transform: "translateY(0)",
                })
                .show();
            }
          });
          
          // Check if there are more items in this category and render show more button
          const totalCategoryItems = categoryItems.length;
          if (totalCategoryItems > limit) {
            this.renderShowMoreButton(category, totalCategoryItems, limit);
          } else {
            this.removeShowMoreButton();
          }
        });
      }

      renderShowMoreButton(category, totalItems = null, shownItems = null) {
        // Remove existing button first
        this.removeShowMoreButton();
        
        const container = $(".menu-grid");
        if (container.length === 0) return;
        
        // Get limits from instance or button
        let fullLimit = this.fullLimit;
        if (totalItems !== null && (fullLimit === -1 || fullLimit > totalItems)) {
          fullLimit = totalItems;
        }
        
        const showMoreHtml = `
          <div class="show-more-container" style="text-align: center; margin: 2rem 0;">
            <button class="show-more-btn" 
                    data-initial-limit="${this.initialLimit}" 
                    data-full-limit="${fullLimit}" 
                    data-menu="${this.container.data('menu') || 'all'}" 
                    data-category="${category}" 
                    data-all-filter="true">
              Show More Items
            </button>
          </div>
        `;
        
        container.after(showMoreHtml);
      }

      removeShowMoreButton() {
        $(".show-more-container").remove();
      }

      openVideo(videoModal) {
        // Close any open videos first
        this.closeAllVideos();

        // Open the selected video
        videoModal.addClass("active");
        this.body.addClass("video-open");

        // Prevent body scroll
        this.body.css("overflow", "hidden");
      }

      closeVideo(videoModal) {
        videoModal.removeClass("active");
        this.body.removeClass("video-open");

        // Re-enable body scroll
        setTimeout(() => {
          if (!$(".video-modal.active").length) {
            this.body.css("overflow", "");
          }
        }, this.animationSpeed);
      }

      openGlobalVideo(
        videoUrl,
        title,
        description,
        galleryImages = [],
        price = "",
        variations = [],
        itemId = null
      ) {
        // Close any open videos first
        this.closeAllVideos();

        // Set video content
        const modal = $("#globalVideoModal");
        const titleEl = modal.find(".video-modal-title");
        const descEl = modal.find(".video-modal-description");
        const iframe = modal.find("iframe");
        const galleryContainer = modal.find(".video-modal-gallery");

        titleEl.text(title || "Video");
        descEl.text(description || "");

        // Process video URL to add autoplay parameters if enabled
        const processedVideoUrl = this.enableVideoAutoplay
          ? this.addAutoplayToVideoUrl(videoUrl)
          : videoUrl;
        iframe.attr("src", processedVideoUrl);

        // Handle price display
        const priceEl = modal.find(".video-modal-price");
        if (priceEl.length > 0) {
          const priceStr = String(price || "").trim();
          if (priceStr !== "") {
            priceEl.text(priceStr);
            priceEl.show();
          } else {
            priceEl.hide();
          }
        }

        // Handle variations display
        const variationsEl = modal.find(".video-modal-variations");
        if (
          variationsEl.length > 0 &&
          variations &&
          Array.isArray(variations) &&
          variations.length > 0
        ) {
          variationsEl.empty();
          variations.forEach((variation, index) => {
            const activeClass = index === 0 ? "active" : "";
            const button = $(
              `<button class="modal-size-tag ${activeClass}" data-price="${variation.price}" data-size="${variation.size}">${variation.size} • ${variation.price}</button>`
            );
            variationsEl.append(button);
          });
          variationsEl.show();
        } else if (variationsEl.length > 0) {
          variationsEl.hide();
        }

        // Handle order actions display based on settings (independent of variations)
        const actionsEl = modal.find(".video-modal-actions");
        if (actionsEl.length > 0) {
          const showOrderOnline = menuPopupAjax.showOrderOnlineModal === "1";
          const showWhatsApp = menuPopupAjax.showWhatsappModal === "1";

          // Show/hide individual buttons based on settings
          const addToCartBtn = actionsEl.find(".video-add-to-cart-btn");
          const whatsappBtn = actionsEl.find(".video-order-whatsapp-btn");

          if (showOrderOnline) {
            addToCartBtn.show();
          } else {
            addToCartBtn.hide();
          }

          if (showWhatsApp) {
            whatsappBtn.show();
          } else {
            whatsappBtn.hide();
          }

          // Show container if at least one button is visible
          if (showOrderOnline || showWhatsApp) {
            actionsEl.show();
          } else {
            actionsEl.hide();
          }
        }

        // Handle gallery
        // Always clear and reset the gallery container COMPLETELY
        galleryContainer
          .empty()
          .removeClass("hidden active")
          .removeAttr("style");

        if (
          galleryImages &&
          Array.isArray(galleryImages) &&
          galleryImages.length > 0
        ) {
          // Create gallery thumbnails
          galleryImages.forEach((imageUrl, index) => {
            const thumbnail = $(
              `<div class="gallery-thumbnail" data-index="${index}"><img src="${imageUrl}" alt="Gallery image ${
                index + 1
              }" /></div>`
            );
            thumbnail.on("click", () => {
              this.openGalleryView(galleryImages, index);
            });
            galleryContainer.append(thumbnail);
          });

          // FORCE DISPLAY SETTING - Multiple approaches
          galleryContainer.show();
          galleryContainer.css("display", "grid");
          galleryContainer.addClass("active");
          galleryContainer.removeClass("hidden");
        } else {
          // Don't hide - just remove active class
          galleryContainer.removeClass("active");
        }

        // Store gallery data and item data for later use
        this.currentGalleryImages = galleryImages;

        // Store item data for order buttons
        if (itemId) {
          modal.data("itemId", itemId);
        }
        if (price) {
          modal.data("price", price);
        }

        // Show modal
        modal.addClass("active");
        this.body.addClass("video-open");
        this.body.css("overflow", "hidden");
      }

      closeGlobalVideo() {
        const modal = $("#globalVideoModal");
        const iframe = modal.find("iframe");
        const galleryContainer = modal.find(".video-modal-gallery");

        // Clear iframe src to stop video
        iframe.attr("src", "");

        // Reset gallery container to initial state (don't leave it in hidden state)
        galleryContainer
          .removeClass("active hidden")
          .removeAttr("style")
          .empty();

        // Hide modal
        modal.removeClass("active");
        this.body.removeClass("video-open");
        this.body.css("overflow", "");
      }

      // Gallery functionality
      openGalleryView(galleryImages, startIndex = 0) {
        this.currentGalleryImages = galleryImages;
        this.currentGalleryIndex = startIndex;

        // Create gallery modal if it doesn't exist
        let galleryModal = $("#galleryFullViewModal");
        if (galleryModal.length === 0) {
          galleryModal = $(
            `<div id="galleryFullViewModal" class="gallery-fullview-modal">
            <div class="gallery-fullview-overlay"></div>
            <div class="gallery-fullview-container">
              <img class="gallery-fullview-image" src="" alt="" />
              <button class="gallery-fullview-close">&times;</button>
              <button class="gallery-nav gallery-nav-prev">&larr;</button>
              <button class="gallery-nav gallery-nav-next">&rarr;</button>
              <div class="gallery-fullview-counter"></div>
            </div>
          </div>`
          );
          $("body").append(galleryModal);

          // Bind gallery events
          this.bindGalleryEvents(galleryModal);
        }

        this.updateGalleryImage(galleryModal);
        galleryModal.addClass("active");
        this.body.addClass("gallery-open");
      }

      updateGalleryImage(galleryModal) {
        const image = galleryModal.find(".gallery-fullview-image");
        const counter = galleryModal.find(".gallery-fullview-counter");
        const prevBtn = galleryModal.find(".gallery-nav-prev");
        const nextBtn = galleryModal.find(".gallery-nav-next");

        image.attr("src", this.currentGalleryImages[this.currentGalleryIndex]);
        counter.text(
          `${this.currentGalleryIndex + 1} / ${
            this.currentGalleryImages.length
          }`
        );

        // Update navigation buttons
        prevBtn.toggle(this.currentGalleryImages.length > 1);
        nextBtn.toggle(this.currentGalleryImages.length > 1);

        prevBtn.prop("disabled", this.currentGalleryIndex === 0);
        nextBtn.prop(
          "disabled",
          this.currentGalleryIndex === this.currentGalleryImages.length - 1
        );
      }

      navigateGallery(direction) {
        const galleryModal = $("#galleryFullViewModal");
        if (direction === "prev" && this.currentGalleryIndex > 0) {
          this.currentGalleryIndex--;
          this.updateGalleryImage(galleryModal);
        } else if (
          direction === "next" &&
          this.currentGalleryIndex < this.currentGalleryImages.length - 1
        ) {
          this.currentGalleryIndex++;
          this.updateGalleryImage(galleryModal);
        }
      }

      closeGalleryView() {
        const galleryModal = $("#galleryFullViewModal");
        galleryModal.removeClass("active");
        this.body.removeClass("gallery-open");
      }

      bindGalleryEvents(galleryModal) {
        // Close gallery on close button
        galleryModal.on("click", ".gallery-fullview-close", (e) => {
          e.preventDefault();
          this.closeGalleryView();
        });

        // Close gallery on background click
        galleryModal.on("click", ".gallery-fullview-overlay", (e) => {
          if ($(e.target).hasClass("gallery-fullview-overlay")) {
            this.closeGalleryView();
          }
        });

        // Navigation buttons
        galleryModal.on("click", ".gallery-nav-prev", (e) => {
          e.preventDefault();
          this.navigateGallery("prev");
        });

        galleryModal.on("click", ".gallery-nav-next", (e) => {
          e.preventDefault();
          this.navigateGallery("next");
        });

        // Keyboard navigation
        $(document).on("keydown.galleryNav", (e) => {
          if (!$(".gallery-fullview-modal.active").length) return;

          switch (e.keyCode) {
            case 37: // Left arrow
              e.preventDefault();
              this.navigateGallery("prev");
              break;
            case 39: // Right arrow
              e.preventDefault();
              this.navigateGallery("next");
              break;
            case 27: // Escape
              e.preventDefault();
              this.closeGalleryView();
              break;
          }
        });
      }

      closeAllVideos() {
        // Close global modal
        const modal = $("#globalVideoModal");
        const iframe = modal.find("iframe");

        iframe.attr("src", "");
        modal.removeClass("active");
        this.body.removeClass("video-open");
        this.body.css("overflow", "");
      }

      setupAnimations() {
        // Reset animation delays for better responsive behavior
        this.menuItems.each((index, item) => {
          $(item).css("animation-delay", `${index * 0.1}s`);
        });
      }

      handleInitialCategory() {
        // Check if there's an initial category from shortcode
        const urlParams = new URLSearchParams(window.location.search);
        const initialCategory = urlParams.get("menu_category");

        if (initialCategory && initialCategory !== "all") {
          this.filterByCategory(initialCategory);
        }
      }

      // Process video URL in frontend (fallback)
      processVideoUrl(url) {
        if (!url) return url;

        // Handle mobile YouTube URLs
        if (url.includes("m.youtube.com")) {
          const match = url.match(/[?&]v=([a-zA-Z0-9_-]+)/);
          if (match) {
            return "https://www.youtube.com/embed/" + match[1];
          }
        }

        // Handle watch URLs
        if (url.includes("youtube.com/watch")) {
          const match = url.match(/[?&]v=([a-zA-Z0-9_-]+)/);
          if (match) {
            return "https://www.youtube.com/embed/" + match[1];
          }
        }

        // Handle youtu.be URLs
        if (url.includes("youtu.be/")) {
          const match = url.match(/youtu\.be\/([a-zA-Z0-9_-]+)/);
          if (match) {
            return "https://www.youtube.com/embed/" + match[1];
          }
        }

        // Return original if already processed
        return url;
      }

      // Add autoplay parameter to video URLs if enabled
      addAutoplayToVideoUrl(url) {
        if (!url) return url;

        try {
          // Handle YouTube URLs
          if (url.includes("youtube.com/embed/") || url.includes("youtu.be/")) {
            const separator = url.includes("?") ? "&" : "?";
            return url + separator + "autoplay=1&enablejsapi=1";
          }

          // Handle Vimeo URLs
          if (url.includes("vimeo.com")) {
            const separator = url.includes("?") ? "&" : "?";
            return url + separator + "autoplay=1";
          }

          // For other URLs, return as is
          return url;
        } catch (error) {
          // If there's any error processing the URL, return the original
          console.warn("Error adding autoplay parameter:", error);
          return url;
        }
      }

      // Search functionality
      performSearch(searchTerm) {
        const container = $(".menu-popup-container");
        const currentCategory =
          container.find(".category-btn.active").data("category") || "all";

        // Show loading state
        container.addClass("loading");

        $.ajax({
          url: menuPopupAjax.ajaxurl,
          type: "POST",
          data: {
            action: "filter_menu_items",
            category: currentCategory,
            menu: container.data("menu") || "all",
            search: searchTerm,
            branch:
              new URLSearchParams(window.location.search).get("branch") || "",
            nonce: menuPopupAjax.nonce,
          },
          success: (response) => {
            if (response.success) {
              // Replace menu grid content
              $(".menu-grid").html(response.data);

              // Get current layout and apply it to the new content
              const currentLayout = container.data("layout") || "grid";

              // Apply layout-specific structure for masonry
              if (currentLayout === "masonry") {
                const items = $(".menu-grid .menu-item");
                const columns = [[], [], []];

                items.each(function (index) {
                  const columnIndex = index % 3;
                  columns[columnIndex].push($(this));
                });

                $(".menu-grid").empty();
                columns.forEach(function (columnItems) {
                  const columnDiv = $("<div class='menu-column'></div>");
                  columnItems.forEach(function (item) {
                    columnDiv.append(item);
                  });
                  $(".menu-grid").append(columnDiv);
                });
              }

              // Apply current layout class
              container.removeClass("layout-grid layout-list layout-masonry");
              container.addClass(`layout-${currentLayout}`);

              // Reinitialize animations
              $(".menu-item").each(function (index) {
                let delay = `${index * 0.1}s`;
                if (currentLayout === "list") {
                  delay = `${index * 0.05}s`;
                } else if (currentLayout === "masonry") {
                  delay = `${Math.floor(index / 3) * 0.1}s`;
                }
                $(this).css("animation-delay", delay);
              });

              // Reinitialize video popup events for new content
              if (window.menuPopup && window.menuPopup.enableVideos) {
                $(".menu-item")
                  .off("click")
                  .on("click", function (e) {
                    if (
                      $(e.target).closest(
                        ".add-to-cart-btn, .order-whatsapp-btn, .size-btn"
                      ).length > 0
                    ) {
                      return;
                    }

                    e.preventDefault();
                    e.stopPropagation();

                    const item = $(this);
                    const videoUrl = item.data("video");
                    const title = item.find(".menu-item-name").text();
                    const description = item
                      .find(".menu-item-description")
                      .text();

                    if (videoUrl && videoUrl.trim() !== "") {
                      let processedUrl = videoUrl;
                      if (
                        videoUrl.includes("m.youtube.com") ||
                        videoUrl.includes("youtube.com/watch")
                      ) {
                        processedUrl =
                          window.menuPopup.processVideoUrl(videoUrl);
                      }

                      const galleryData = item.data("gallery");
                      let galleryImages = [];
                      if (galleryData) {
                        try {
                          if (Array.isArray(galleryData)) {
                            galleryImages = galleryData;
                          } else if (typeof galleryData === "string") {
                            galleryImages = JSON.parse(galleryData);
                          }
                          galleryImages = Array.isArray(galleryImages)
                            ? galleryImages.filter((img) => img && img.trim())
                            : [];
                        } catch (e) {
                          galleryImages = [];
                        }
                      }

                      const currentPrice = item.data("price") || "";
                      const variations = item.data("sizes") || [];

                      window.menuPopup.openGlobalVideo(
                        processedUrl,
                        title,
                        description,
                        galleryImages,
                        currentPrice,
                        variations,
                        item.attr("id") || item.data("itemId") || item.find(".add-to-cart-btn").first().attr("data-item-id") || null
                      );
                    }
                  });
              }
            }
          },
          error: () => {
            console.error("Search request failed");
          },
          complete: () => {
            container.removeClass("loading");
          },
        });
      }

      // Show more functionality
      loadMoreItems(btn, initialLimit, fullLimit, menu, category, allFilter) {
        const container = $(".menu-popup-container");
        
        // Use current category from state if available, fallback to button data
        const currentCategory = this.currentCategory || category;
        const currentMenu = btn.data("menu") || menu || "all";
        
        // Store limits in instance for tracking
        this.initialLimit = initialLimit;
        this.fullLimit = fullLimit;

        // Show loading state
        btn.text("Loading...").prop("disabled", true);

        // Calculate offset for next batch - count visible items only
        const visibleItems = $(".menu-item:visible");
        const currentCount = visibleItems.length;
        const offset = currentCount > 0 ? currentCount : 0;

        $.ajax({
          url: menuPopupAjax.ajaxurl,
          type: "POST",
          data: {
            action: "filter_menu_items",
            category: currentCategory,
            menu: currentMenu,
            offset: offset,
            limit: initialLimit, // Load same batch size
            branch:
              new URLSearchParams(window.location.search).get("branch") || "",
            nonce: menuPopupAjax.nonce,
          },
          success: (response) => {
            if (response.success && response.data) {
              // Get current layout
              const currentLayout = container.data("layout") || "grid";

              // Create temporary container for new items
              const tempContainer = $("<div>").html(response.data);
              let newItems = tempContainer.find(".menu-item");

              if (newItems.length > 0) {
                // Apply layout-specific structure for masonry
                if (currentLayout === "masonry") {
                  // For masonry, distribute new items across existing columns
                  const columns = $(".menu-column");
                  let columnIndex = 0;

                  newItems.each(function () {
                    const item = $(this);
                    if (columnIndex < columns.length) {
                      columns.eq(columnIndex).append(item);
                      columnIndex = (columnIndex + 1) % columns.length;
                    }
                  });
                } else {
                  // For grid/list layout, append to main grid
                  $(".menu-grid").append(newItems);
                }

                // Apply animations to new items
                newItems.each(function (index) {
                  let delay = `${index * 0.1}s`;
                  if (currentLayout === "list") {
                    delay = `${index * 0.05}s`;
                  } else if (currentLayout === "masonry") {
                    delay = `${Math.floor(index / 3) * 0.1}s`;
                  }
                  $(this).css("animation-delay", delay);
                });

                // Check if there are more items available
                const totalLoaded = $(".menu-item").length;
                const hasMore = totalLoaded < fullLimit || fullLimit === -1;

                if (!hasMore) {
                  // No more items, remove the button
                  this.removeShowMoreButton();
                } else {
                  // Reset button state
                  btn.text("Show More Items").prop("disabled", false);
                  // Update the button's data attributes for next click
                  btn.attr("data-full-limit", fullLimit);
                }

                // Re-render show more button with correct category
                const currentCat = this.currentCategory || category;
                this.renderShowMoreButton(currentCat);

                // Reinitialize video popup events for new content
                if (window.menuPopup && window.menuPopup.enableVideos) {
                  newItems.each(function () {
                    const item = $(this);
                    item.off("click").on("click", function (e) {
                      if (
                        $(e.target).closest(
                          ".add-to-cart-btn, .order-whatsapp-btn, .size-btn"
                        ).length > 0
                      ) {
                        return;
                      }

                      e.preventDefault();
                      e.stopPropagation();

                      const videoUrl = item.data("video");
                      const title = item.find(".menu-item-name").text();
                      const description = item
                        .find(".menu-item-description")
                        .text();

                      if (videoUrl && videoUrl.trim() !== "") {
                        let processedUrl = videoUrl;
                        if (
                          videoUrl.includes("m.youtube.com") ||
                          videoUrl.includes("youtube.com/watch")
                        ) {
                          processedUrl =
                            window.menuPopup.processVideoUrl(videoUrl);
                        }

                        const galleryData = item.data("gallery");
                        let galleryImages = [];
                        if (galleryData) {
                          try {
                            if (Array.isArray(galleryData)) {
                              galleryImages = galleryData;
                            } else if (typeof galleryData === "string") {
                              galleryImages = JSON.parse(galleryData);
                            }
                            galleryImages = Array.isArray(galleryImages)
                              ? galleryImages.filter((img) => img && img.trim())
                              : [];
                          } catch (e) {
                            galleryImages = [];
                          }
                        }

                        const currentPrice = item.data("price") || "";
                        const variations = item.data("sizes") || [];

                        window.menuPopup.openGlobalVideo(
                          processedUrl,
                          title,
                          description,
                          galleryImages,
                          currentPrice,
                          variations,
                          item.attr("id") || item.data("itemId") || item.find(".add-to-cart-btn").first().attr("data-item-id") || null
                        );
                      }
                    });
                  });
                }
              } else {
                // No more items, remove the button
                btn.closest(".show-more-container").remove();
              }
            }
          },
          error: () => {
            // Reset button on error
            btn.text("Show More Items").prop("disabled", false);
          },
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

      // Cart functionality
      initCart() {
        if (
          menuPopupAjax.orderingEnabled === "1" ||
          menuPopupAjax.whatsappEnabled === "1"
        ) {
          this.cart = this.getCartFromStorage();
          this.updateCartUI();
        }
      }

      initOrdering() {
        if (menuPopupAjax.orderingEnabled === "1") {
          // Bind cart button events - multiple layers of event prevention
          if (
            menuPopupAjax.orderingEnabled === "1" ||
            menuPopupAjax.whatsappEnabled === "1"
          ) {
            // Capture all possible click events on add to cart button with video modal prevention
            $(document).on(
              "mousedown touchstart pointerdown",
              ".add-to-cart-btn",
              (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                e.stopPropagation();
                const btn = $(e.currentTarget);

                // Get base values
                let itemId = btn.data("item-id");
                const baseItemId = itemId;
                let name = btn.data("name");
                let price = btn.data("price");

                // Check if a variation is selected
                const selectedSize = btn.data("selected-size");
                const selectedPrice = btn.data("selected-price");

                if (selectedSize && selectedPrice) {
                  // Variation is selected - use selected values and modify ID/name
                  price = selectedPrice;
                  itemId = `${itemId}_${selectedSize.replace(/\s+/g, "_")}`; // Replace spaces with underscores for ID
                  name = `${name} (${selectedSize})`;
                }

                this.addToCart(
                  itemId,
                  name,
                  price,
                  baseItemId,
                  selectedSize && selectedPrice ? String(selectedSize) : ""
                );
                return false;
              }
            );

            // Additional prevention on click event with video modal prevention
            $(document).on("click", ".add-to-cart-btn", (e) => {
              e.preventDefault();
              e.stopImmediatePropagation();
              e.stopPropagation();
              return false;
            });

            // Prevent event bubbling to parent menu items
            $(document).on(
              "mouseup touchend pointerup",
              ".add-to-cart-btn",
              (e) => {
                e.stopPropagation();
              }
            );

            // Prevent parent menu item click when add-to-cart button is clicked
            $(document).on("click", ".add-to-cart-btn", (e) => {
              e.stopPropagation();
            });
          }

          // Bind WhatsApp button events - multiple layers of event prevention
          if (menuPopupAjax.whatsappEnabled === "1") {
            // Prevent parent menu item click when WhatsApp button is clicked
            $(document).on("click", ".order-whatsapp-btn", (e) => {
              e.stopPropagation();
            });

            $(document).on(
              "mousedown touchstart pointerdown",
              ".order-whatsapp-btn",
              (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                e.stopPropagation();
                const btn = $(e.currentTarget);

                // Get base values
                let name = btn.data("name");
                let price = btn.data("price");

                // Check if a variation is selected
                const selectedSize = btn.data("selected-size");
                const selectedPrice = btn.data("selected-price");

                if (selectedSize && selectedPrice) {
                  // Variation is selected - use selected values and modify name
                  price = selectedPrice;
                  name = `${name} (${selectedSize})`;
                }

                this.orderViaWhatsApp(name, price);
                return false;
              }
            );

            $(document).on("click", ".order-whatsapp-btn", (e) => {
              e.preventDefault();
              e.stopImmediatePropagation();
              e.stopPropagation();
              return false;
            });

            $(document).on(
              "mouseup touchend pointerup",
              ".order-whatsapp-btn",
              (e) => {
                e.stopPropagation();
              }
            );
          }
        }
      }

      getCartFromStorage() {
        const cartData = localStorage.getItem("menuPopupCart");
        return cartData ? JSON.parse(cartData) : {};
      }

      saveCartToStorage(cart) {
        localStorage.setItem("menuPopupCart", JSON.stringify(cart));
      }

      addToCart(itemId, name, price, baseId, size) {
        // Ensure price is a valid number by removing currency symbols and extracting numeric value
        let cleanPrice = price;
        if (typeof cleanPrice === "string") {
          // Remove currency symbols and extract first number found
          const priceMatch = cleanPrice.replace(/[^\d.-]/g, "");
          cleanPrice = parseFloat(priceMatch) || 0;
        } else {
          cleanPrice = parseFloat(cleanPrice) || 0;
        }

        const quantity = this.cart[itemId] ? this.cart[itemId].quantity + 1 : 1;
        this.cart[itemId] = {
          id: baseId || (this.cart[itemId] && this.cart[itemId].id) || 0,
          size: size || "",
          name: name,
          price: cleanPrice,
          quantity: quantity,
        };
        this.saveCartToStorage(this.cart);
        this.updateCartUI();

        // Visual feedback
        const btn = $(`.add-to-cart-btn[data-item-id="${itemId}"]`);
        const originalText = btn.html();
        btn.html("✓ Added!").addClass("added");
        setTimeout(() => {
          btn.html(originalText).removeClass("added");
        }, 1000);

        // Show cart notification every time an item is added
        this.showCartNotification();
      }

      // Toggle cart flyout functionality
      toggleCartFlyout() {
        const cartCounter = $("#cart-toggle-btn");
        const flyout = cartCounter.find(".cart-flyout");

        if (flyout.hasClass("visible")) {
          this.hideCartFlyout();
        } else {
          this.showCartFlyout();
        }
      }

      // Close cart flyout when X button is clicked
      closeCartFlyout() {
        this.hideCartFlyout();
      }

      showCartFlyout() {
        const cartCounter = $("#cart-toggle-btn");
        const flyout = cartCounter.find(".cart-flyout");

        // Hide any other open flyouts
        $(".cart-flyout").removeClass("visible");

        // Update flyout items FIRST
        this.updateCartFlyoutItems();

        // Bind flyout button handlers each time flyout opens
        flyout
          .find(".cart-clear-all")
          .off("click.cartButtons")
          .on("click.cartButtons", (e) => {
            e.stopPropagation();
            e.preventDefault();
            e.stopImmediatePropagation();
            this.clearCart();
            this.hideCartFlyout();
            return false;
          });

        flyout
          .find(".cart-checkout")
          .off("click.cartButtons")
          .on("click.cartButtons", (e) => {
            e.stopPropagation();
            e.preventDefault();
            e.stopImmediatePropagation();
            this.hideCartFlyout();
            this.completeOrder();
            return false;
          });

        // Bind close button
        flyout
          .find(".cart-flyout-close")
          .off("click.cartClose")
          .on("click.cartClose", (e) => {
            e.stopPropagation();
            e.preventDefault();
            e.stopImmediatePropagation();
            this.closeCartFlyout();
            return false;
          });

        // Show flyout
        flyout.addClass("visible");
        cartCounter.addClass("flyout-open");

        // Add click outside handler with proper event delegation - ONLY for outside clicks
        setTimeout(() => {
          $(document)
            .off("click.cartFlyoutClose")
            .on("click.cartFlyoutClose", (e) => {
              // Prevent any clicks inside the flyout from triggering this handler
              if ($(e.target).closest(".cart-flyout").length > 0) {
                // Click is inside the cart flyout - do NOT close
                return;
              }

              // Only close if clicking on the cart toggle button itself OR completely outside
              // If we're NOT on the toggle button, then we close
              if (!$(e.target).closest("#cart-toggle-btn").length) {
                this.hideCartFlyout();
              } else {
                // Clicked on cart toggle button - toggle instead of close
                // Don't hide here, the toggle logic will handle it
                return;
              }
            });
        }, 100);
      }

      hideCartFlyout() {
        const cartCounter = $("#cart-toggle-btn");
        const flyout = cartCounter.find(".cart-flyout");

        flyout.removeClass("visible");
        cartCounter.removeClass("flyout-open");

        $(document).off("click.cartFlyoutClose");
      }

      // Update cart flyout items with controls - Optimized for performance
      updateCartFlyoutItems() {
        const flyoutItems = $("#cart-flyout-items");

        // Use more efficient DOM clearing
        flyoutItems[0].innerHTML = "";

        if (Object.keys(this.cart).length === 0) {
          flyoutItems.html('<div class="cart-empty">Your cart is empty</div>');
          return;
        }

        // Pre-build HTML string for better performance
        let itemsHtml = "";
        const cartEntries = Object.entries(this.cart);

        for (let i = 0; i < cartEntries.length; i++) {
          const [itemId, item] = cartEntries[i];
          const itemTotal = item.price * item.quantity;

          itemsHtml += `
            <div class="cart-flyout-item" data-id="${itemId}">
              <div class="cart-flyout-item-info">
                <span class="cart-flyout-item-name">${item.name}</span>
                <span class="cart-flyout-item-price">${
                  menuPopupAjax.currencySymbol
                }${itemTotal.toFixed(2)}</span>
              </div>
              <div class="cart-flyout-item-controls">
                <button class="flyout-quantity-dec" data-item-id="${itemId}" data-action="dec">-</button>
                <span class="flyout-quantity">${item.quantity}</span>
                <button class="flyout-quantity-inc" data-item-id="${itemId}" data-action="inc">+</button>
              </div>
            </div>
          `;
        }

        // Single DOM insertion for better performance
        flyoutItems.html(itemsHtml);

        // Batch event binding for better performance
        this.bindCartFlyoutEvents();
      }

      // Separate event binding for better performance
      bindCartFlyoutEvents() {
        const flyoutItems = $("#cart-flyout-items");

        // Unbind existing events first
        flyoutItems.off("click.quantity");

        // Bind events with optimized selector
        flyoutItems.on(
          "click.quantity",
          ".flyout-quantity-dec, .flyout-quantity-inc",
          (e) => {
            e.stopPropagation();
            e.preventDefault();
            e.stopImmediatePropagation();

            const button = $(e.target);
            const action = button.data("action");
            const targetItemId = button.data("item-id");

            // Update cart synchronously for immediate response
            if (this.cart[targetItemId]) {
              if (action === "dec") {
                if (this.cart[targetItemId].quantity > 0) {
                  this.cart[targetItemId].quantity--;
                  if (this.cart[targetItemId].quantity === 0) {
                    delete this.cart[targetItemId];
                  }
                }
              } else if (action === "inc") {
                this.cart[targetItemId].quantity++;
              }

              // Save to storage
              this.saveCartToStorage(this.cart);

              // Update only the specific item in the flyout (no re-render)
              const flyoutItem = flyoutItems.find(`[data-id="${targetItemId}"]`);
              if (flyoutItem.length) {
                const item = this.cart[targetItemId];
                if (item) {
                  flyoutItem.find(".flyout-quantity").text(item.quantity);
                  flyoutItem.find(".cart-flyout-item-price").text(
                    menuPopupAjax.currencySymbol + (item.price * item.quantity).toFixed(2)
                  );
                } else {
                  // Item was removed, re-render flyout
                  this.updateCartFlyoutItems();
                }
              }

              this.updateCartUI();
            }

            return false;
          }
        );
      }

      updateCartUI() {
        // Cache calculations for better performance
        const cartCount = Object.keys(this.cart).length;
        const totalItems = Object.values(this.cart).reduce(
          (sum, item) => sum + item.quantity,
          0
        );
        const totalPrice = Object.values(this.cart)
          .reduce((sum, item) => sum + item.price * item.quantity, 0)
          .toFixed(2);

        // Update cart counter - optimized DOM operations
        let cartCounter = $("#cart-toggle-btn");
        if (cartCounter.length === 0) {
          // Create cart counter with optimized HTML structure
          const cartHtml = `
            <div id="cart-toggle-btn" class="cart-toggle-btn">
              <div class="cart-toggle-icon">
                <span class="cart-toggle-count">${totalItems}</span>
              </div>
              <div class="cart-flyout">
                <div class="cart-flyout-header">
                  <h4>Your Order</h4>
                  <span class="cart-items-count">${totalItems} items</span>
                  <button class="cart-flyout-close">&times;</button>
                </div>
                <div class="cart-flyout-body" id="cart-flyout-items"></div>
                <div class="cart-flyout-footer">
                  <div class="cart-flyout-total">
                    <span class="cart-total-label">Total:</span>
                    <span class="cart-total-amount">${menuPopupAjax.currencySymbol}${totalPrice}</span>
                  </div>
                  <div class="cart-flyout-actions">
                    <button class="cart-clear-all">
                      <span>Clear All</span>
                    </button>
                    <button class="cart-checkout">
                      <span>Order Now</span>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          `;

          // Single DOM insertion for better performance
          $(cartHtml).appendTo("body");
          cartCounter = $("#cart-toggle-btn");

          // Bind events once during creation
          this.bindCartCounterEvents();
        } else {
          // Optimized updates for existing elements
          const countEl = cartCounter.find(".cart-toggle-count");
          const itemsCount = cartCounter.find(".cart-items-count");
          const totalAmount = cartCounter.find(".cart-total-amount");

          // Batch DOM updates for better performance
          if (countEl.text() !== String(totalItems)) {
            countEl.addClass("count-update");
            // Use requestAnimationFrame for smooth animations
            requestAnimationFrame(() => {
              countEl.text(totalItems).removeClass("count-update");
            });
          }

          // Batch text updates
          itemsCount.text(`${totalItems} items`);
          totalAmount.text(`${menuPopupAjax.currencySymbol}${totalPrice}`);
        }

        // Optimized visibility and styling updates
        cartCounter.toggleClass("visible", totalItems > 0);
        cartCounter.toggleClass("large", totalItems > 9);

        // Conditional updates for better performance
        if (totalItems > 0) {
          this.updateCartFlyoutItems();
        }

        // Debounced notification update
        this.debounce(() => {
          this.updateCartNotification(totalItems, totalPrice);
        }, 50)();
      }

      // Separate event binding for better performance
      bindCartCounterEvents() {
        const cartCounter = $("#cart-toggle-btn");

        cartCounter.on("click", (e) => {
          e.stopPropagation();

          // Don't toggle if clicking on flyout elements when flyout is visible
          if ($(e.target).closest(".cart-flyout").length > 0) {
            return; // Allow click events inside flyout to work normally
          }

          this.toggleCartFlyout();
        });
      }

      showCartNotification() {
        let notification = $("#cart-notification");
        if (notification.length === 0) {
          // Create notification with optimized HTML structure
          const notificationHtml = `
            <div id="cart-notification" class="cart-notification">
              <div class="cart-notification-content">
                <span class="cart-notification-text">Item added to cart!</span>
                <button class="cart-notification-close">&times;</button>
                <button class="cart-notification-view">View Cart</button>
              </div>
            </div>
          `;

          $(notificationHtml).appendTo("body");
          notification = $("#cart-notification");

          // Bind events once during creation
          notification.find(".cart-notification-close").on("click", () => {
            this.hideCartNotification();
          });

          notification.find(".cart-notification-view").on("click", () => {
            this.showCartModal();
            this.hideCartNotification();
          });
        }

        // Show notification immediately
        notification.addClass("visible");

        // Use requestAnimationFrame for hide timing
        this.notificationTimeout = setTimeout(() => {
          this.hideCartNotification();
        }, 3000);
      }

      hideCartNotification() {
        const notification = $("#cart-notification");
        if (notification.length > 0) {
          notification.removeClass("visible");
          // Clear timeout if it exists
          if (this.notificationTimeout) {
            clearTimeout(this.notificationTimeout);
            this.notificationTimeout = null;
          }
          // Remove from DOM after animation completes
          setTimeout(() => {
            notification.remove();
          }, 300);
        }
      }

      updateCartNotification(itemCount, totalPrice) {
        const notification = $("#cart-notification");
        if (notification.length > 0) {
          const text = `Cart: ${itemCount} item${
            itemCount !== 1 ? "s" : ""
          } ($${totalPrice})`;
          notification
            .find(".cart-notification-text")
            .text(`${itemCount} item${itemCount !== 1 ? "s" : ""} in cart`);
          notification
            .find(".cart-notification-view")
            .text(`View Cart ($${totalPrice})`);
        }
      }

      showCartModal() {
        let cartModal = $("#cart-modal");
        if (cartModal.length === 0) {
          cartModal = $(`
            <div id="cart-modal" class="cart-modal">
              <div class="cart-modal-overlay"></div>
              <div class="cart-modal-content">
                <div class="cart-modal-header">
                  <h3>Your Order</h3>
                  <button class="cart-modal-close">&times;</button>
                </div>
                <div class="cart-modal-body">
                  <div id="cart-items"></div>
                  <div class="cart-total">
                    <strong>Total: <span id="cart-total-price">$0.00</span></strong>
                  </div>
                  <div id="payment-methods-section" class="payment-methods-section">
                    <h4>Payment Method</h4>
                    <div id="payment-methods-list">
                      <div class="payment-method-option">
                        <input type="radio" name="payment_method" id="payment_cod" value="cod" checked>
                        <label for="payment_cod">
                          <strong>Cash on Delivery</strong>
                          <span class="payment-desc">Pay when you receive your order</span>
                        </label>
                      </div>
                      <div class="payment-method-option">
                        <input type="radio" name="payment_method" id="payment_bank" value="bank_transfer">
                        <label for="payment_bank">
                          <strong>Bank Transfer</strong>
                          <span class="payment-desc">Transfer to our bank account</span>
                        </label>
                      </div>
                      <div class="payment-method-option">
                        <input type="radio" name="payment_method" id="payment_fawry" value="fawry">
                        <label for="payment_fawry">
                          <strong>Fawry</strong>
                          <span class="payment-desc">Pay via Fawry payment gateway</span>
                        </label>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="cart-modal-footer">
                  <button class="cart-clear-btn">Clear Cart</button>
                  <button class="cart-order-btn">Complete Order</button>
                </div>
              </div>
            </div>
          `);
          $("body").append(cartModal);

          // Bind cart modal events
          cartModal.find(".cart-modal-close").on("click", () => {
            cartModal.removeClass("active");
          });

          cartModal.find(".cart-modal-overlay").on("click", () => {
            cartModal.removeClass("active");
          });

          cartModal.find(".cart-clear-btn").on("click", () => {
            this.clearCart();
            cartModal.removeClass("active");
          });

          cartModal.find(".cart-order-btn").on("click", () => {
            this.completeOrder();
          });

          // Close on escape
          $(document).on("keydown.cartModal", (e) => {
            if (e.keyCode === 27) {
              cartModal.removeClass("active");
            }
          });
        }

        // Show cart with smooth animation
        setTimeout(() => {
          this.renderCartItemsWithAnimation();
          
          // Load payment methods if enabled
          if (window.menuPopupPayment) {
            this.loadPaymentMethodsInModal();
          }
        }, 150);

        cartModal.addClass("active");
        this.body.addClass("cart-modal-open");
      }

      renderCartItems() {
        const cartItems = $("#cart-items");
        cartItems.empty();

        if (Object.keys(this.cart).length === 0) {
          cartItems.html("<p>Your cart is empty.</p>");
          $("#cart-modal .cart-modal-footer").hide();
          return;
        }

        $("#cart-modal .cart-modal-footer").show();

        let total = 0;
        Object.entries(this.cart).forEach(([itemId, item]) => {
          const itemTotal = item.price * item.quantity;
          total += itemTotal;

          const itemEl = $(`
            <div class="cart-item" data-id="${itemId}">
              <div class="cart-item-info">
                <span class="cart-item-name">${item.name}</span>
                <span class="cart-item-price">$${item.price.toFixed(
                  2
                )} each</span>
              </div>
              <div class="cart-item-controls">
                <button class="cart-quantity-dec">-</button>
                <span class="cart-quantity">${item.quantity}</span>
                <button class="cart-quantity-inc">+</button>
                <button class="cart-item-remove">×</button>
              </div>
              <div class="cart-item-total">$${itemTotal.toFixed(2)}</div>
            </div>
          `);

          itemEl.find(".cart-quantity-dec").on("click", () => {
            if (item.quantity > 0) {
              item.quantity--;
              if (item.quantity === 0) {
                // Remove item from cart when quantity reaches 0
                delete this.cart[itemId];
              }
              this.saveCartToStorage(this.cart);
              this.renderCartItems();
              this.updateCartUI();
            }
          });

          itemEl.find(".cart-quantity-inc").on("click", () => {
            item.quantity++;
            this.saveCartToStorage(this.cart);
            this.renderCartItems();
            this.updateCartUI();
          });

          itemEl.find(".cart-item-remove").on("click", () => {
            delete this.cart[itemId];
            this.saveCartToStorage(this.cart);
            this.renderCartItems();
            this.updateCartUI();
          });

          cartItems.append(itemEl);
        });

        $("#cart-total-price").text("$" + total.toFixed(2));
      }

      clearCart() {
        this.cart = {};
        this.saveCartToStorage(this.cart);
        this.updateCartUI();
      }

      completeOrder() {
        if (Object.keys(this.cart).length === 0) {
          alert("Your cart is empty!");
          return;
        }

        $("#cart-modal").removeClass("active");

        // Show order confirmation dialog before proceeding
        this.showOrderConfirmation();
      }

      // Build customer information fields for confirmation modal
      buildCustomerFields() {
        let fields = "";

        // Name field (required)
        if (menuPopupAjax.customerNameRequired === "1") {
          fields += '<div class="form-field">';
          fields +=
            '<label for="confirmation_customer_name">Full Name <span class="required">*</span></label>';
          fields +=
            '<input type="text" id="confirmation_customer_name" name="confirmation_customer_name" required>';
          fields += "</div>";
        }

        // Phone field (required)
        if (menuPopupAjax.customerPhoneRequired === "1") {
          fields += '<div class="form-field">';
          fields +=
            '<label for="confirmation_customer_phone">Phone Number <span class="required">*</span></label>';
          fields +=
            '<input type="tel" id="confirmation_customer_phone" name="confirmation_customer_phone" required>';
          fields += "</div>";
        }

        // Email field (optional)
        if (menuPopupAjax.customerEmailEnabled === "1") {
          fields += '<div class="form-field">';
          fields +=
            '<label for="confirmation_customer_email">Email Address</label>';
          fields +=
            '<input type="email" id="confirmation_customer_email" name="confirmation_customer_email">';
          fields += "</div>";
        }

        // Delivery zones field (shown conditionally when delivery is selected)
        if (menuPopupAjax.deliveryEnabled === "1") {
          fields +=
            '<div class="form-field delivery-zones-field" style="display:none;">';
          fields +=
            '<label for="confirmation_delivery_zone">Delivery Zone <span class="required">*</span></label>';
          fields +=
            '<select id="confirmation_delivery_zone" name="confirmation_delivery_zone" required>';

          // Populate zones from array
          if (
            menuPopupAjax.deliveryZones &&
            menuPopupAjax.deliveryZones.length > 0
          ) {
            fields += '<option value="">Select Delivery Zone</option>';
            menuPopupAjax.deliveryZones.forEach(function (zone, index) {
              if (zone.enabled !== false) {
                fields +=
                  '<option value="' +
                  zone.id +
                  '" data-fee="' +
                  zone.fee +
                  '" data-min-order="' +
                  (zone.min_order || 0) +
                  '">' +
                  zone.name +
                  " - " +
                  menuPopupAjax.currencySymbol +
                  zone.fee +
                  "</option>";
              }
            });
          } else {
            fields += '<option value="">No delivery zones available</option>';
          }

          fields += "</select>";
          fields +=
            '<p class="description">Delivery fee and minimum order will be calculated based on your zone selection.</p>';
          fields += "</div>";
        }

        // Delivery address field (shown conditionally when delivery is selected)
        if (menuPopupAjax.deliveryEnabled === "1") {
          fields +=
            '<div class="form-field delivery-address-field" style="display:none;">';
          fields +=
            '<label for="confirmation_customer_address">Delivery Address <span class="required">*</span></label>';
          fields +=
            '<textarea id="confirmation_customer_address" name="confirmation_customer_address" rows="3" required></textarea>';
          fields += "</div>";
        }

        // Special instructions field (optional)
        if (menuPopupAjax.specialInstructionsEnabled === "1") {
          fields += '<div class="form-field">';
          fields +=
            '<label for="confirmation_special_instructions">Special Instructions</label>';
          fields +=
            '<textarea id="confirmation_special_instructions" name="confirmation_special_instructions" rows="3" placeholder="Any special requests or dietary notes..."></textarea>';
          fields += "</div>";
        }

        return fields;
      }

      // Validate customer information fields
      validateCustomerFields() {
        let isValid = true;
        let errorMessage = "";

        // Validate required name field
        if (menuPopupAjax.customerNameRequired === "1") {
          const nameField = $("#confirmation_customer_name");
          if (!nameField.val().trim()) {
            isValid = false;
            errorMessage += "Please enter your full name.\n";
            nameField.addClass("error");
          } else {
            nameField.removeClass("error");
          }
        }

        // Validate required phone field
        if (menuPopupAjax.customerPhoneRequired === "1") {
          const phoneField = $("#confirmation_customer_phone");
          if (!phoneField.val().trim()) {
            isValid = false;
            errorMessage += "Please enter your phone number.\n";
            phoneField.addClass("error");
          } else {
            phoneField.removeClass("error");
          }
        }

        // Validate delivery zone if delivery is selected
        const selectedOrderType = $(
          'input[name="confirmation_order_type"]:checked'
        ).val();
        if (
          selectedOrderType === "delivery" &&
          menuPopupAjax.deliveryEnabled === "1"
        ) {
          if (window.mpDelivery && window.mpDelivery.isMapMode()) {
            // Map delivery: a valid pin inside a delivery area is required
            const quote = window.mpDelivery.quote();
            if (!window.mpDelivery.hasPin()) {
              isValid = false;
              errorMessage += "Please choose your delivery location on the map.\n";
            } else if (!quote) {
              isValid = false;
              errorMessage += "Still checking delivery for your location, please try again in a moment.\n";
            } else if (!quote.ok) {
              isValid = false;
              errorMessage += quote.message + "\n";
            } else if (quote.min_order > 0) {
              const cartSub = Object.values(this.cart).reduce(
                (sum, item) => sum + item.price * item.quantity,
                0
              );
              const cartTotal =
                cartSub + cartSub * (parseFloat(menuPopupAjax.taxRate || 0) / 100);
              if (cartTotal < quote.min_order) {
                isValid = false;
                errorMessage +=
                  "Minimum order for your area is " +
                  menuPopupAjax.currencySymbol +
                  Number(quote.min_order).toFixed(2) +
                  ".\n";
              }
            }
          } else {
            const zoneField = $("#confirmation_delivery_zone");
            if (!zoneField.val()) {
              isValid = false;
              errorMessage += "Please select a delivery zone.\n";
              zoneField.addClass("error");
            } else {
              zoneField.removeClass("error");
            }
          }

          const addressField = $("#confirmation_customer_address");
          if (!addressField.val().trim()) {
            isValid = false;
            errorMessage += "Please enter your delivery address.\n";
            addressField.addClass("error");
          } else {
            addressField.removeClass("error");
          }
        }

        if (!isValid) {
          alert(errorMessage.trim());
        }

        return isValid;
      }

      // Order confirmation dialog
      showOrderConfirmation() {
        let confirmModal = $("#order-confirmation-modal");
        if (confirmModal.length === 0) {
          const customerFields = this.buildCustomerFields();
          
          // Build order type section based on delivery enabled setting
          let orderTypeSection = '';
          if (menuPopupAjax.deliveryEnabled === "1") {
            orderTypeSection = `
              <!-- Order Type Selection -->
              <div class="order-type-selection" id="order-type-selection">
                <h4>Select Order Type</h4>
                <div class="order-type-options-confirmation">
                  <label class="order-type-option-confirmation">
                    <input type="radio" name="confirmation_order_type" value="pickup" checked>
                    <span class="option-content-confirmation">
                      <span class="order-icon-confirmation">🏪</span> Pickup
                    </span>
                  </label>
                  <label class="order-type-option-confirmation">
                    <input type="radio" name="confirmation_order_type" value="delivery">
                    <span class="option-content-confirmation">
                      <span class="order-icon-confirmation">🚚</span> Delivery
                    </span>
                  </label>
                </div>
              </div>`;
          } else {
            // Hidden field for pickup if delivery not enabled
            orderTypeSection = '<input type="hidden" name="confirmation_order_type" value="pickup">';
          }
          
          confirmModal = $(`
            <div id="order-confirmation-modal" class="order-confirmation-modal">
              <div class="order-confirmation-overlay"></div>
              <div class="order-confirmation-content">
                <div class="order-confirmation-header">
                  <span class="order-confirmation-icon">📋</span>
                  <h3>Confirm Your Order</h3>
                </div>
                <div class="order-confirmation-body">
                  <div id="confirmation-items"></div>
                  <div class="confirmation-summary">
                    <div class="confirmation-subtotal">
                      <span>Subtotal:</span>
                      <span id="confirmation-subtotal">$0.00</span>
                    </div>
                    <div class="confirmation-tax">
                      <span>Tax (${menuPopupAjax.taxRate || 0}%):</span>
                      <span id="confirmation-tax">$0.00</span>
                    </div>
                    <div class="confirmation-total">
                      <span><strong>Total:</strong></span>
                      <span id="confirmation-total"><strong>$0.00</strong></span>
                    </div>
                  </div>
                  <!-- Customer Information Fields -->
                  <div class="customer-information-section">
                    <h4>Customer Information</h4>
                    <div class="customer-fields">
                      ${customerFields}
                    </div>
                  </div>
                  ${orderTypeSection}
                  <!-- Delivery Order Indicator -->
                  <div class="delivery-order-indicator" id="delivery-order-indicator" style="display: none;">
                    <div class="delivery-indicator-content">
                      <span class="delivery-icon">🚚</span>
                      <span class="delivery-text">This is a delivery order</span>
                    </div>
                  </div>
                  <!-- Payment Method Selection -->
                  <div class="payment-methods-section" id="payment-methods-section">
                    <h4>Select Payment Method</h4>
                    <div class="payment-methods-list" id="payment-methods-list-confirmation">
                      <div class="payment-method-option">
                        <input type="radio" name="confirmation_payment_method" id="confirmation_payment_cod" value="cod" checked>
                        <label for="confirmation_payment_cod">
                          <strong>Cash on Delivery</strong>
                          <span class="payment-desc">Pay when you receive your order</span>
                        </label>
                      </div>
                      <div class="payment-method-option">
                        <input type="radio" name="confirmation_payment_method" id="confirmation_payment_bank" value="bank_transfer">
                        <label for="confirmation_payment_bank">
                          <strong>Bank Transfer</strong>
                          <span class="payment-desc">Transfer to our bank account</span>
                        </label>
                      </div>
                      <div class="payment-method-option">
                        <input type="radio" name="confirmation_payment_method" id="confirmation_payment_fawry" value="fawry">
                        <label for="confirmation_payment_fawry">
                          <strong>Fawry</strong>
                          <span class="payment-desc">Pay via Fawry payment gateway</span>
                        </label>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="order-confirmation-footer">
                  <button class="confirmation-cancel">Modify Order</button>
                  <button class="confirmation-proceed">Confirm</button>
                </div>
              </div>
            </div>
          `);
          confirmModal.appendTo("body");

          // Bind confirmation modal events
          confirmModal.find(".confirmation-cancel").on("click", () => {
            confirmModal.removeClass("active");
            this.showCartModal(); // Go back to cart modal
          });

          confirmModal.find(".confirmation-proceed").on("click", () => {
            // Validate customer information before proceeding
            if (this.validateCustomerFields()) {
              confirmModal.removeClass("active");
              this.showOrderReviewModal();
            }
          });

          confirmModal.find(".order-confirmation-overlay").on("click", () => {
            confirmModal.removeClass("active");
          });

          $(document).on("keydown.confirmation", (e) => {
            if (e.keyCode === 27) {
              confirmModal.removeClass("active");
            }
          });

          // Handle order type selection for delivery fields and button text
          confirmModal
            .find('input[name="confirmation_order_type"]')
            .on("change", function () {
              const selectedType = $(this).val();
              const deliveryIndicator = $("#delivery-order-indicator");
              const deliveryZoneField = confirmModal.find(
                ".delivery-zones-field"
              );
              const deliveryAddressField = confirmModal.find(
                ".delivery-address-field"
              );

              if (selectedType === "delivery") {
                deliveryIndicator.show();
                deliveryZoneField.slideDown(300);
                deliveryAddressField.slideDown(300);
                deliveryAddressField.find("textarea").prop("required", true);
              } else {
                deliveryIndicator.hide();
                deliveryZoneField.slideUp(300);
                deliveryAddressField.slideUp(300);
                deliveryAddressField
                  .find("textarea")
                  .prop("required", false)
                  .val("");
              }
            });
        }

        this.renderOrderConfirmation();
        confirmModal.addClass("active");
        
        // Load payment methods in order confirmation modal
        if (window.menuPopupPayment) {
          this.loadPaymentMethodsInModal();
        }
      }

      renderOrderConfirmation() {
        const confirmItems = $("#confirmation-items");
        const subtotalEl = $("#confirmation-subtotal");
        const taxEl = $("#confirmation-tax");
        const totalEl = $("#confirmation-total");
        const deliveryIndicator = $("#delivery-order-indicator");

        confirmItems.empty();

        let subtotal = 0;
        Object.entries(this.cart).forEach(([itemId, item]) => {
          const itemTotal = item.price * item.quantity;
          subtotal += itemTotal;

          const itemEl = $(`
            <div class="confirmation-item">
              <div class="confirmation-item-details">
                <span class="confirmation-item-name">${item.name}</span>
                <span class="confirmation-item-quantity">x${
                  item.quantity
                }</span>
              </div>
            <div class="confirmation-item-price">${
              menuPopupAjax.currencySymbol
            }${itemTotal.toFixed(2)}</div>
            </div>
          `);
          confirmItems.append(itemEl);
        });

        const tax = subtotal * (parseFloat(menuPopupAjax.taxRate || 0) / 100);
        const total = subtotal + tax;

        subtotalEl.text(
          `${menuPopupAjax.currencySymbol}${subtotal.toFixed(2)}`
        );
        taxEl.text(`${menuPopupAjax.currencySymbol}${tax.toFixed(2)}`);
        totalEl.html(
          `<strong>${menuPopupAjax.currencySymbol}${total.toFixed(2)}</strong>`
        );

        // Restore previously entered customer information
        this.restoreCustomerInfo();

        // Delivery indicator is controlled by radio button selection above
      }

      // Restore customer information in confirmation modal
      restoreCustomerInfo() {
        // Restore name
        if (menuPopupAjax.customerNameRequired === "1") {
          $("#confirmation_customer_name").val(this.customerInfo.name);
        }

        // Restore phone
        if (menuPopupAjax.customerPhoneRequired === "1") {
          $("#confirmation_customer_phone").val(this.customerInfo.phone);
        }

        // Restore email
        if (menuPopupAjax.customerEmailEnabled === "1") {
          $("#confirmation_customer_email").val(this.customerInfo.email);
        }

        // Restore order type
        $(
          `input[name="confirmation_order_type"][value="${this.customerInfo.order_type}"]`
        ).prop("checked", true);

        // Restore delivery zone
        if (menuPopupAjax.deliveryEnabled === "1") {
          $("#confirmation_delivery_zone").val(this.customerInfo.delivery_zone);
        }

        // Restore address
        if (menuPopupAjax.deliveryEnabled === "1") {
          $("#confirmation_customer_address").val(this.customerInfo.address);
        }

        // Restore special instructions
        if (menuPopupAjax.specialInstructionsEnabled === "1") {
          $("#confirmation_special_instructions").val(
            this.customerInfo.special_instructions
          );
        }

        // Trigger change event to show/hide delivery fields based on order type
        $(`input[name="confirmation_order_type"]:checked`).trigger("change");
      }

      // Save customer information from confirmation modal
      saveCustomerInfo() {
        // Save name
        if (menuPopupAjax.customerNameRequired === "1") {
          this.customerInfo.name = $("#confirmation_customer_name").val();
        }

        // Save phone
        if (menuPopupAjax.customerPhoneRequired === "1") {
          this.customerInfo.phone = $("#confirmation_customer_phone").val();
        }

        // Save email
        if (menuPopupAjax.customerEmailEnabled === "1") {
          this.customerInfo.email = $("#confirmation_customer_email").val();
        }

        // Save order type
        this.customerInfo.order_type =
          $('input[name="confirmation_order_type"]:checked').val() || "pickup";

        // Save delivery zone
        if (menuPopupAjax.deliveryEnabled === "1") {
          this.customerInfo.delivery_zone = $(
            "#confirmation_delivery_zone"
          ).val();
        }

        // Save address
        if (menuPopupAjax.deliveryEnabled === "1") {
          this.customerInfo.address = $("#confirmation_customer_address").val();
        }

        // Save special instructions
        if (menuPopupAjax.specialInstructionsEnabled === "1") {
          this.customerInfo.special_instructions = $(
            "#confirmation_special_instructions"
          ).val();
        }
      }

      // New order review modal
      showOrderReviewModal() {
        // Save customer information before proceeding to review
        this.saveCustomerInfo();

        // Get selected order type from confirmation modal
        const selectedType = $(
          'input[name="confirmation_order_type"]:checked'
        ).val();
        this.selected_order_type = selectedType; // Store for later use

        let reviewModal = $("#order-review-modal");
        if (reviewModal.length === 0) {
          reviewModal = $(`
            <div id="order-review-modal" class="order-review-modal">
              <div class="order-review-overlay"></div>
              <div class="order-review-content">
                <div class="order-review-header">
                  <span class="order-review-icon">📋</span>
                  <h3>Review Your Order</h3>
                </div>
                <div class="order-review-body">
                  <div id="review-order-items"></div>
                  <div class="review-order-summary">
                    <div class="review-subtotal">
                      <span>Subtotal:</span>
                      <span id="review-subtotal">$0.00</span>
                    </div>
                    <div class="review-tax">
                      <span>Tax (${menuPopupAjax.taxRate || 0}%):</span>
                      <span id="review-tax">$0.00</span>
                    </div>
                    <div class="review-delivery-fee" id="review-delivery-fee-row" style="display: none;">
                      <span>Delivery Fee:</span>
                      <span id="review-delivery-fee">$0.00</span>
                    </div>
                    <div class="review-total">
                      <span><strong>Total:</strong></span>
                      <span id="review-total"><strong>$0.00</strong></span>
                    </div>
                  </div>
                </div>
                <div class="order-review-footer">
                  <button class="review-modify-order">Modify Your Order</button>
                  <button class="review-place-order">Place Order</button>
                </div>
              </div>
            </div>
          `);
          reviewModal.appendTo("body");

          // Bind review modal events
          reviewModal.find(".review-modify-order").on("click", () => {
            reviewModal.removeClass("active");
            this.showOrderConfirmation(); // Go back to confirmation modal
          });

          reviewModal.find(".review-place-order").on("click", () => {
            reviewModal.removeClass("active");
            this.processConfirmedOrder(); // Proceed with order
          });

          reviewModal.find(".order-review-overlay").on("click", () => {
            reviewModal.removeClass("active");
          });

          $(document).on("keydown.review", (e) => {
            if (e.keyCode === 27) {
              reviewModal.removeClass("active");
            }
          });
        }

        this.renderOrderReview();
        reviewModal.addClass("active");

        // Re-quote with the final cart value (free-delivery thresholds depend on it)
        if (
          selectedType === "delivery" &&
          window.mpDelivery &&
          window.mpDelivery.isMapMode() &&
          window.mpDelivery.hasPin()
        ) {
          const cartSub = Object.values(this.cart).reduce(
            (sum, item) => sum + item.price * item.quantity,
            0
          );
          window.mpDelivery.refresh(
            cartSub + cartSub * (parseFloat(menuPopupAjax.taxRate || 0) / 100)
          );
        }
      }

      renderOrderReview() {
        const reviewItems = $("#review-order-items");
        const subtotalEl = $("#review-subtotal");
        const taxEl = $("#review-tax");
        const deliveryFeeRow = $("#review-delivery-fee-row");
        const deliveryFeeEl = $("#review-delivery-fee");
        const totalEl = $("#review-total");

        reviewItems.empty();

        let subtotal = 0;
        Object.entries(this.cart).forEach(([itemId, item]) => {
          const itemTotal = item.price * item.quantity;
          subtotal += itemTotal;

          const itemEl = $(`
            <div class="review-order-item">
              <div class="review-item-details">
                <span class="review-item-name">${item.name}</span>
                <span class="review-item-quantity">x${item.quantity}</span>
              </div>
              <div class="review-item-price">${
                menuPopupAjax.currencySymbol
              }${itemTotal.toFixed(2)}</div>
            </div>
          `);
          reviewItems.append(itemEl);
        });

        const tax = subtotal * (parseFloat(menuPopupAjax.taxRate || 0) / 100);
        let deliveryFee = 0;
        let selectedZoneName = "";

        // Calculate delivery fee if delivery is selected
        if (this.selected_order_type === "delivery") {
          // Map delivery: the fee comes from the server quote for the pinned location
          const mapQuote =
            window.mpDelivery && window.mpDelivery.isMapMode()
              ? window.mpDelivery.quote()
              : null;
          if (mapQuote && mapQuote.ok) {
            deliveryFee = parseFloat(mapQuote.fee || 0);
            selectedZoneName = mapQuote.zone_name || "";
          }

          // Get the selected zone from the confirmation modal
          const selectedZoneId =
            window.mpDelivery && window.mpDelivery.isMapMode()
              ? ""
              : $("#confirmation_delivery_zone").val();

          if (
            selectedZoneId &&
            window.menuPopupAjax &&
            window.menuPopupAjax.deliveryZones
          ) {
            const selectedZone = window.menuPopupAjax.deliveryZones.find(
              (zone) => zone.id === selectedZoneId
            );
            if (selectedZone) {
              deliveryFee = parseFloat(selectedZone.fee || 0);
              selectedZoneName = selectedZone.name || "";
            }
          }
        }

        const finalTotal = subtotal + tax + deliveryFee;

        subtotalEl.text(
          `${menuPopupAjax.currencySymbol}${subtotal.toFixed(2)}`
        );
        taxEl.text(`${menuPopupAjax.currencySymbol}${tax.toFixed(2)}`);

        if (deliveryFee > 0) {
          deliveryFeeEl.text(
            `${menuPopupAjax.currencySymbol}${deliveryFee.toFixed(2)}`
          );
          deliveryFeeRow.show();
        } else {
          deliveryFeeRow.hide();
        }

        totalEl.html(
          `<strong>${menuPopupAjax.currencySymbol}${finalTotal.toFixed(
            2
          )}</strong>`
        );
      }

      processConfirmedOrder() {
        // Get selected order type from confirmation modal
        const selectedType = $(
          'input[name="confirmation_order_type"]:checked'
        ).val();
        this.selected_order_type = selectedType; // Store for later use

        // Determine order method
        if (
          menuPopupAjax.whatsappEnabled === "1" &&
          menuPopupAjax.restaurantWhatsapp
        ) {
          // Send via WhatsApp using customer info from confirmation modal
          this.sendOrderViaWhatsAppFromConfirmation();
        } else {
          // Process order directly using customer info from confirmation modal
          this.processOrderFromConfirmation();
        }
      }

      showOrderForm() {
        let orderForm = $("#order-form-modal");
        if (orderForm.length === 0) {
          const fields = this.buildOrderFormFields();
          orderForm = $(`
            <div id="order-form-modal" class="order-form-modal">
              <div class="order-form-overlay"></div>
              <div class="order-form-content">
                <div class="order-form-header">
                  <h3>Complete Your Order</h3>
                  <button class="order-form-close">&times;</button>
                </div>
                <form id="order-form" class="order-form">
                  ${fields}
                  <div class="order-form-actions">
                    <button type="button" class="order-form-cancel">Cancel</button>
                    <button type="submit" class="order-form-submit">Place Order</button>
                  </div>
                </form>
              </div>
            </div>
          `);
          $("body").append(orderForm);

          // Bind form events
          orderForm
            .find(".order-form-close, .order-form-overlay")
            .on("click", () => {
              orderForm.removeClass("active");
            });

          orderForm.find(".order-form-cancel").on("click", () => {
            orderForm.removeClass("active");
          });

          // Bind order type change event for conditional address field
          if (menuPopupAjax.deliveryEnabled === "1") {
            orderForm.find('input[name="order_type"]').on("change", (e) => {
              const selectedType = $(e.target).val();
              const addressField = orderForm.find(".delivery-address-field");
              const zonesField = orderForm.find(".delivery-zones-field");
              if (selectedType === "delivery") {
                addressField
                  .slideDown(300)
                  .find("textarea")
                  .prop("required", true);
                zonesField.slideDown(300);
              } else {
                addressField
                  .slideUp(300)
                  .find("textarea")
                  .prop("required", false)
                  .val("");
                zonesField.slideUp(300);
              }
            });
          }

          orderForm.find("#order-form").on("submit", (e) => {
            e.preventDefault();
            this.submitOrderForm();
          });

          $(document).on("keydown.orderForm", (e) => {
            if (e.keyCode === 27) {
              orderForm.removeClass("active");
            }
          });

          // Pre-select the order type from confirmation modal AFTER event handlers are bound
          if (this.selected_order_type) {
            orderForm
              .find(
                `input[name="order_type"][value="${this.selected_order_type}"]`
              )
              .prop("checked", true);
            // Trigger change event to show/hide delivery fields
            orderForm
              .find(
                `input[name="order_type"][value="${this.selected_order_type}"]`
              )
              .trigger("change");
          }
        }

        orderForm.addClass("active");
      }

      buildOrderFormFields() {
        let fields = '<div class="order-form-fields">';

        // Order Type Selection (Pickup vs Delivery)
        if (menuPopupAjax.deliveryEnabled === "1") {
          fields += '<div class="form-field order-type-field">';
          fields +=
            '<label class="field-label">Order Type <span class="required">*</span></label>';
          fields += '<div class="order-type-options">';
          fields += '<label class="order-type-option">';
          fields +=
            '<input type="radio" name="order_type" value="pickup" checked>';
          fields +=
            '<span class="option-content"><span class="order-icon">🏪</span> Pickup</span>';
          fields += "</label>";
          fields += '<label class="order-type-option">';
          fields += '<input type="radio" name="order_type" value="delivery">';
          fields +=
            '<span class="option-content"><span class="order-icon">🚚</span> Delivery</span>';
          fields += "</label>";
          fields += "</div>";
          fields += "</div>";
        } else {
          // Hidden field for pickup if delivery not enabled
          fields += '<input type="hidden" name="order_type" value="pickup">';
        }

        if (menuPopupAjax.customerNameRequired === "1") {
          fields += '<div class="form-field">';
          fields +=
            '<label for="customer_name">Full Name <span class="required">*</span></label>';
          fields +=
            '<input type="text" id="customer_name" name="customer_name" required>';
          fields += "</div>";
        }

        if (menuPopupAjax.customerPhoneRequired === "1") {
          fields += '<div class="form-field">';
          fields +=
            '<label for="customer_phone">Phone Number <span class="required">*</span></label>';
          fields +=
            '<input type="tel" id="customer_phone" name="customer_phone" required>';
          fields += "</div>";
        }

        if (menuPopupAjax.customerEmailEnabled === "1") {
          fields += '<div class="form-field">';
          fields += '<label for="customer_email">Email Address</label>';
          fields +=
            '<input type="email" id="customer_email" name="customer_email">';
          fields += "</div>";
        }

        // Delivery zones field (shown conditionally)
        if (menuPopupAjax.deliveryEnabled === "1") {
          fields +=
            '<div class="form-field delivery-zones-field" style="display:none;">';
          fields +=
            '<label for="delivery_zone">Delivery Zone <span class="required">*</span></label>';
          fields += '<select id="delivery_zone" name="delivery_zone" required>';

          // Populate zones from array
          if (
            menuPopupAjax.deliveryZones &&
            menuPopupAjax.deliveryZones.length > 0
          ) {
            fields += '<option value="">Select Delivery Zone</option>';
            menuPopupAjax.deliveryZones.forEach(function (zone, index) {
              if (zone.enabled !== false) {
                // Allow enabled to be undefined (true by default)
                fields +=
                  '<option value="' +
                  zone.id +
                  '" data-fee="' +
                  zone.fee +
                  '" data-min-order="' +
                  (zone.min_order || 0) +
                  '">' +
                  zone.name +
                  " - " +
                  menuPopupAjax.currencySymbol +
                  zone.fee +
                  "</option>";
              }
            });
          } else {
            fields += '<option value="">No delivery zones available</option>';
          }

          fields += "</select>";
          fields +=
            '<p class="description">Delivery fee and minimum order will be calculated based on your zone selection.</p>';
          fields += "</div>";
        }

        // Delivery address field - always added when delivery is enabled, regardless of setting
        if (menuPopupAjax.deliveryEnabled === "1") {
          fields +=
            '<div class="form-field delivery-address-field" style="display:none;">';
          fields +=
            '<label for="customer_address">Delivery Address <span class="required">*</span></label>';
          fields +=
            '<textarea id="customer_address" name="customer_address" rows="3"></textarea>';
          fields += "</div>";
        }

        // Address field for non-delivery use (when delivery not enabled but address collection is enabled)
        if (
          menuPopupAjax.customerAddressEnabled === "1" &&
          menuPopupAjax.deliveryEnabled !== "1"
        ) {
          fields += '<div class="form-field">';
          fields += '<label for="customer_address">Address</label>';
          fields +=
            '<textarea id="customer_address" name="customer_address" rows="3"></textarea>';
          fields += "</div>";
        }

        if (menuPopupAjax.specialInstructionsEnabled === "1") {
          fields += '<div class="form-field">';
          fields +=
            '<label for="special_instructions">Special Instructions</label>';
          fields +=
            '<textarea id="special_instructions" name="special_instructions" rows="3" placeholder="Any special requests or dietary notes..."></textarea>';
          fields += "</div>";
        }

        fields += "</div>";
        return fields;
      }

      submitOrderForm() {
        const formData = {
          order_type: $('input[name="order_type"]:checked').val() || "pickup",
          customer_name: $("#customer_name").val(),
          customer_phone: $("#customer_phone").val(),
          customer_email: $("#customer_email").val(),
          customer_address: $("#customer_address").val(),
          delivery_zone: $("#delivery_zone").val(),
          special_instructions: $("#special_instructions").val(),
        };

        // Validate required fields
        let isValid = true;
        if (
          menuPopupAjax.customerNameRequired === "1" &&
          !formData.customer_name.trim()
        ) {
          isValid = false;
        }
        if (
          menuPopupAjax.customerPhoneRequired === "1" &&
          !formData.customer_phone.trim()
        ) {
          isValid = false;
        }

        if (!isValid) {
          alert("Please fill in all required fields.");
          return;
        }

        // Process order based on available methods
        // Priority: Database (online ordering) > WhatsApp > Manual processing
        if (menuPopupAjax.orderingEnabled === "1") {
          // Save to database if online ordering is enabled
          this.saveOrderToDatabase(formData);
        } else if (
          menuPopupAjax.whatsappEnabled === "1" &&
          menuPopupAjax.restaurantWhatsapp
        ) {
          // Send via WhatsApp if enabled
          this.sendOrderViaWhatsApp(formData);
        } else {
          // No automated ordering methods available - show error and prevent order loss
          alert(
            "Ordering is currently unavailable. Please contact the restaurant directly to place your order."
          );
          // Don't close modal or clear cart - let user try again or contact restaurant
          return;
        }
      }

      orderViaWhatsApp(name, price, fullCart = false) {
        let message = `🍽️ New Order from ${window.location.hostname}\n\n`;

        if (fullCart) {
          message += "Order Details:\n";
          Object.entries(this.cart).forEach(([itemId, item]) => {
            message += `• ${item.name} x${item.quantity} ($${item.price.toFixed(
              2
            )} each)\n`;
          });
          const total = Object.values(this.cart).reduce(
            (sum, item) => sum + item.price * item.quantity,
            0
          );
          message += `\n💰 Total: $${
            menuPopupAjax.currencySymbol
          }${total.toFixed(2)}`;
        } else {
          message += `Order: ${name}\nPrice: $${menuPopupAjax.currencySymbol}${price}`;
        }

        const whatsappUrl = `https://wa.me/${menuPopupAjax.restaurantWhatsapp.replace(
          /\D/g,
          ""
        )}?text=${encodeURIComponent(message)}`;
        window.open(whatsappUrl, "_blank");
      }

      sendOrderViaWhatsApp(formData) {
        this.setOrderButtonsBusy(true, "Sending...");

        // Open the tab right away (inside the click) so the browser does not block it
        const waWindow = window.open("", "_blank");

        this.placeOrder(formData, "whatsapp")
          .done((response) => {
            if (!(response && response.success)) {
              this.orderRequestId = null;
              if (waWindow) {
                waWindow.close();
              }
              this.setOrderButtonsBusy(false);
              alert(this.orderErrorMessage(response));
              return;
            }

            const order = response.data;
            const cur = order.currency_symbol || menuPopupAjax.currencySymbol || "";

            let message = `🍽️ New Order #${order.order_number} from ${window.location.hostname}\n\n`;
            message += `👤 Customer: ${formData.customer_name}\n`;
            message += `📱 Phone: ${formData.customer_phone}\n`;
            message += `🔖 Order Type: ${
              order.order_type === "delivery" ? "🚚 Delivery" : "🏪 Pickup"
            }\n`;

            if (formData.customer_email) {
              message += `📧 Email: ${formData.customer_email}\n`;
            }

            if (order.order_type === "delivery") {
              if (order.delivery_zone) {
                message += `🏙️ Delivery Zone: ${order.delivery_zone}\n`;
              }
              if (formData.customer_address) {
                message += `📍 Address: ${formData.customer_address}\n`;
              }
              if (formData.address_details) {
                message += `🏢 Details: ${formData.address_details}\n`;
              }
              if (formData.delivery_lat !== undefined && formData.delivery_lat !== "") {
                message += `🗺️ Map: https://www.google.com/maps?q=${formData.delivery_lat},${formData.delivery_lng}\n`;
              }
            }

            message += "\n📋 Order Details:\n";
            (order.items || []).forEach((line) => {
              message += `• ${line.name} x${line.quantity} (${cur}${Number(
                line.price
              ).toFixed(2)} each)\n`;
            });

            message += `\n💰 Subtotal: ${cur}${Number(order.subtotal).toFixed(2)}`;
            if (Number(order.tax) > 0) {
              message += `\n💸 Tax: ${cur}${Number(order.tax).toFixed(2)}`;
            }
            if (Number(order.delivery_fee) > 0) {
              message += `\n🚛 Delivery Fee: ${cur}${Number(
                order.delivery_fee
              ).toFixed(2)}`;
            }
            message += `\n🏷️ Total: ${cur}${Number(order.total).toFixed(2)}`;

            if (formData.special_instructions) {
              message += `\n\n📝 Special Instructions: ${formData.special_instructions}`;
            }

            const whatsappUrl = `https://wa.me/${String(
              menuPopupAjax.restaurantWhatsapp || ""
            ).replace(/\D/g, "")}?text=${encodeURIComponent(message)}`;

            if (waWindow) {
              waWindow.location.href = whatsappUrl;
            } else {
              window.open(whatsappUrl, "_blank");
            }

            this.setOrderButtonsBusy(false);
            $("#order-form-modal, #order-confirmation-modal, #order-review-modal").removeClass(
              "active"
            );
            this.orderRequestId = null;
            this.clearCart();
            this.showSuccessNotification("Order sent successfully!");
          })
          .fail(() => {
            if (waWindow) {
              waWindow.close();
            }
            this.setOrderButtonsBusy(false);
            alert("Error connecting to server. Please try again.");
          });
      }

      // Mini-cart preview system
      showMiniCart() {
        let miniCart = $("#mini-cart-preview");
        if (miniCart.length === 0) {
          miniCart = $(`
            <div id="mini-cart-preview" class="mini-cart-preview">
              <div class="mini-cart-header">
                <span class="mini-cart-count">${
                  Object.keys(this.cart).length
                }</span> items in cart
              </div>
              <div class="mini-cart-items" id="mini-cart-items"></div>
              <div class="mini-cart-footer">
                <div class="mini-cart-total">Total: <span id="mini-cart-total-price">$0.00</span></div>
                <button class="mini-cart-view-btn">View Full Cart</button>
              </div>
            </div>
          `);
          $("body").append(miniCart);

          miniCart.find(".mini-cart-view-btn").on("click", () => {
            this.showCartModal();
            this.hideMiniCart();
          });
        }

        this.updateMiniCartItems();
        miniCart.addClass("visible");
      }

      hideMiniCart() {
        $("#mini-cart-preview").removeClass("visible");
        setTimeout(() => {
          $("#mini-cart-preview").remove();
        }, 300);
      }

      updateMiniCartItems() {
        const miniCartItems = $("#mini-cart-items");
        const miniCartTotal = $("#mini-cart-total-price");
        miniCartItems.empty();

        let total = 0;
        let itemPreview = [];
        Object.entries(this.cart).forEach(([itemId, item]) => {
          const itemTotal = item.price * item.quantity;
          total += itemTotal;

          // Show only first 3 items in mini-cart
          if (itemPreview.length < 3) {
            itemPreview.push(`
              <div class="mini-cart-item">
                <span class="mini-cart-item-name">${item.name}</span>
                <span class="mini-cart-item-qty">x${item.quantity}</span>
              </div>
            `);
          }
        });

        miniCartItems.html(itemPreview.join(""));
        if (Object.keys(this.cart).length > 3) {
          miniCartItems.append(
            `<div class="mini-cart-more">+${
              Object.keys(this.cart).length - 3
            } more items</div>`
          );
        }

        miniCartTotal.text(
          `$${menuPopupAjax.currencySymbol}${total.toFixed(2)}`
        );
      }

      // Animated cart items rendering
      renderCartItemsWithAnimation() {
        const cartItems = $("#cart-items");
        cartItems.empty();

        if (Object.keys(this.cart).length === 0) {
          cartItems.html("<p>Your cart is empty.</p>");
          $("#cart-modal .cart-modal-footer").hide();
          return;
        }

        $("#cart-modal .cart-modal-footer").show();

        let total = 0;
        let index = 0;
        Object.entries(this.cart).forEach(([itemId, item]) => {
          const itemTotal = item.price * item.quantity;
          total += itemTotal;

          const itemEl = $(`
            <div class="cart-item animated" data-id="${itemId}" style="animation-delay: ${
            index * 0.1
          }s">
              <div class="cart-item-info">
                <span class="cart-item-name">${item.name}</span>
                <span class="cart-item-price">$${item.price.toFixed(
                  2
                )} each</span>
              </div>
              <div class="cart-item-controls">
                <button class="cart-quantity-dec">-</button>
                <span class="cart-quantity">${item.quantity}</span>
                <button class="cart-quantity-inc">+</button>
                <button class="cart-item-remove">×</button>
              </div>
              <div class="cart-item-total">$${itemTotal.toFixed(2)}</div>
            </div>
          `);

          // Enhanced quantity controls with better feedback
          itemEl.find(".cart-quantity-dec").on("click", () => {
            if (item.quantity > 0) {
              item.quantity--;
              if (item.quantity === 0) {
                // Remove item from cart when quantity reaches 0
                delete this.cart[itemId];
              }
              this.saveCartToStorage(this.cart);
              this.renderCartItemsWithAnimation();
            }
          });

          itemEl.find(".cart-quantity-inc").on("click", () => {
            item.quantity++;
            this.saveCartToStorage(this.cart);
            this.updateCartItemDisplay(itemEl, item);
          });

          itemEl.find(".cart-item-remove").on("click", () => {
            itemEl.addClass("removing");
            setTimeout(() => {
              delete this.cart[itemId];
              this.saveCartToStorage(this.cart);
              this.renderCartItemsWithAnimation();
              this.updateCartUI();
            }, 200);
          });

          cartItems.append(itemEl);
          index++;
        });

        $("#cart-total-price").text(
          `${menuPopupAjax.currencySymbol}${total.toFixed(2)}`
        );
      }

      // Update individual cart item display without animation (faster response)
      updateCartItemDisplay(itemElement, item) {
        const itemTotal = item.price * item.quantity;
        itemElement
          .find(".cart-quantity")
          .text(item.quantity);
        itemElement
          .find(".cart-item-total")
          .text(`${itemTotal.toFixed(2)}`);

        this.updateCartUI();
      }
      
      // Load payment methods in cart modal
      loadPaymentMethodsInModal() {
        const paymentSection = $('#payment-methods-section');
        
        // Payment methods are now hardcoded in HTML - always show the section when payment is enabled
        if (paymentSection.length > 0) {
          paymentSection.show();
          
          // If dynamic loading is available, try to load from server
          if (window.menuPopupPayment && window.menuPopupPaymentFrontend) {
            window.menuPopupPaymentFrontend.loadPaymentMethods();
          }
        }
      }

      // Layout switching functionality
      initLayoutSwitching() {
        const container = this.container;
        const currentLayout = container.data("layout") || "grid";

        // Remove existing layout classes except the current one
        container.removeClass("layout-grid layout-list layout-masonry");

        // Apply the current layout class
        container.addClass(`layout-${currentLayout}`);

        // Set up intersection observer for dynamic layout adjustments if needed
        this.setupLayoutObserver();
      }

      // Setup intersection observer to handle layout-specific behavior
      setupLayoutObserver() {
        // Observe menu items for layout-specific animations or behaviors
        const observer = new IntersectionObserver(
          (entries) => {
            entries.forEach((entry) => {
              if (entry.isIntersecting) {
                const item = $(entry.target);
                item.addClass("in-viewport");

                // Layout-specific entry animations
                const container = this.container;
                if (container.hasClass("layout-list")) {
                  item.css("animation-delay", `${item.index() * 0.05}s`);
                } else if (container.hasClass("layout-masonry")) {
                  item.css(
                    "animation-delay",
                    `${Math.floor(item.index() / 3) * 0.1}s`
                  );
                }
              }
            });
          },
          {
            threshold: 0.1,
            rootMargin: "50px 0px",
          }
        );

        // Observe all menu items
        this.menuItems.each((_, item) => {
          observer.observe(item);
        });
      }

      // Success notification system - Optimized for performance
      showSuccessNotification(message) {
        let notification = $("#success-notification");
        if (notification.length === 0) {
          // Create notification with optimized HTML structure
          const notificationHtml = `
            <div id="success-notification" class="success-notification">
              <div class="success-notification-content">
                <span class="success-icon">✓</span>
                <span class="success-message">${message}</span>
              </div>
            </div>
          `;

          $(notificationHtml).appendTo("body");
          notification = $("#success-notification");
        } else {
          notification.find(".success-message").text(message);
        }

        // Show notification immediately
        notification.addClass("visible");

        // Use single timeout with proper cleanup
        this.successTimeout = setTimeout(() => {
          notification.removeClass("visible");
          setTimeout(() => {
            notification.remove();
          }, 300);
        }, 3000);
      }

      // Speech synthesis toggle functionality
      initSpeechToggle() {
        // Check if admin has enabled voice pronunciation feature
        if (
          typeof menuPopupAjax === "undefined" ||
          menuPopupAjax.pronounceOnHoverEnabled !== "1"
        ) {
          return;
        }

        // Make initSpeechToggle bind to a lexical scope to avoid 'this' binding issues
        const self = this;

        // Function to actually initialize the toggle
        function initializeToggle() {
          // Find search buttons container to position toggle beside search input
          const searchButtonsContainer = $(".search-buttons");

          if (searchButtonsContainer.length === 0) {
            // Retry after a short delay
            setTimeout(initializeToggle, 500);
            return;
          }

          // Check if toggle already exists
          if (
            searchButtonsContainer.find(".speech-toggle-container").length > 0
          ) {
            return;
          }

          // Create speech toggle container for proper positioning
          const toggleContainer = $(`
        <div class="speech-toggle-container">
          <button class="speech-toggle-btn" title="Toggle speech synthesis" aria-label="Toggle speech synthesis">
            <span class="speech-toggle-icon">🔊</span>
          </button>
        </div>
      `);

          // Add toggle button to search buttons container
          searchButtonsContainer.append(toggleContainer);

          // Bind toggle event using delegated binding (works even if button is added later)
          $(document).on("click.speechToggle", ".speech-toggle-btn", (e) => {
            e.preventDefault();
            e.stopPropagation();
            self.toggleSpeechSynthesis();
          });

          // Update initial state
          self.updateSpeechToggleState();
        }

        // Initial attempt
        initializeToggle();
      }

      toggleSpeechSynthesis() {
        // Get current preference from localStorage (same key as SpeechSynthesis uses)
        const currentEnabled = this.getSpeechUserPreference();

        const enabled = currentEnabled === null ? true : !currentEnabled; // If null, toggle to true

        // Save preference using the same localStorage key as SpeechSynthesis
        this.saveSpeechUserPreference(enabled);

        // Update toggle button state
        this.updateSpeechToggleState();

        // Notify speech synthesis instance to update its voice setup
        if (window.menuPopupSpeechSynthesis) {
          window.menuPopupSpeechSynthesis.setupPreferredVoice();
        }

        // Show user feedback
        this.showSpeechToggleFeedback(enabled);
      }

      updateSpeechToggleState() {
        const toggleBtn = $(".speech-toggle-btn");
        if (toggleBtn.length === 0) {
          return;
        }

        const isEnabled = this.getSpeechUserPreference();

        // Update button appearance
        toggleBtn.toggleClass("enabled", isEnabled);
        toggleBtn.toggleClass("disabled", !isEnabled);

        // Update icon and text
        const icon = toggleBtn.find(".speech-toggle-icon");
        const text = toggleBtn.find(".speech-toggle-text");

        if (isEnabled === false) {
          icon.text("🔇");
          if (text.length > 0) text.text("Pronounce Off");
        } else {
          icon.text("🔊");
          if (text.length > 0) text.text("Pronounce On");
        }
      }

      getSpeechUserPreference() {
        // Use the same localStorage key as SpeechSynthesis class: "menuPopupSpeechSynthesisEnabled"
        const preference = localStorage.getItem(
          "menuPopupSpeechSynthesisEnabled"
        );
        if (preference === null) return null; // Use admin setting
        return preference === "true";
      }

      saveSpeechUserPreference(enabled) {
        // Use the same localStorage key as SpeechSynthesis class
        localStorage.setItem(
          "menuPopupSpeechSynthesisEnabled",
          enabled.toString()
        );
      }

      showSpeechToggleFeedback(enabled) {
        const message = enabled
          ? "Voice pronunciation enabled"
          : "Voice pronunciation disabled";
        this.showSuccessNotification(message);
      }

      getSpeechUserPreferences() {
        const prefs = localStorage.getItem("speechUserPreferences");
        if (prefs) {
          return JSON.parse(prefs);
        }

        // Default preferences
        return {
          enabled: true, // Default to enabled
          language: "en", // Default language
        };
      }

      saveSpeechUserPreferences(prefs) {
        localStorage.setItem("speechUserPreferences", JSON.stringify(prefs));
      }

      // Send order via WhatsApp using customer info from confirmation modal
      sendOrderViaWhatsAppFromConfirmation() {
        // Get customer information from confirmation modal
        const formData = {
          order_type: this.selected_order_type || "pickup",
          customer_name: $("#confirmation_customer_name").val(),
          customer_phone: $("#confirmation_customer_phone").val(),
          customer_email: $("#confirmation_customer_email").val(),
          customer_address: $("#confirmation_customer_address").val(),
          delivery_zone: $("#confirmation_delivery_zone").val(),
          special_instructions: $("#confirmation_special_instructions").val(),
        };
        Object.assign(formData, this.deliveryExtras(formData));

        // Send via WhatsApp
        this.sendOrderViaWhatsApp(formData);
      }

      // Process order directly using customer info from confirmation modal
      processOrderFromConfirmation() {
        // Get customer information from confirmation modal
        const selectedPaymentMethod = $(
          'input[name="confirmation_payment_method"]:checked'
        ).val();
        
        const formData = {
          order_type: this.selected_order_type || "pickup",
          customer_name: $("#confirmation_customer_name").val(),
          customer_phone: $("#confirmation_customer_phone").val(),
          customer_email: $("#confirmation_customer_email").val(),
          customer_address: $("#confirmation_customer_address").val(),
          delivery_zone: $("#confirmation_delivery_zone").val(),
          special_instructions: $("#confirmation_special_instructions").val(),
          payment_method: selectedPaymentMethod || "",
        };
        Object.assign(formData, this.deliveryExtras(formData));

        // Process order based on payment method
        if (!selectedPaymentMethod) {
          // No payment method selected - use default behavior
          if (menuPopupAjax.orderingEnabled === "1") {
            this.saveOrderToDatabase(formData);
          } else {
            alert(
              "Ordering is currently unavailable. Please contact the restaurant directly to place your order."
            );
            return;
          }
        } else if (selectedPaymentMethod === 'cod' || selectedPaymentMethod === 'bank_transfer') {
          // COD or Bank Transfer - save order immediately
          if (menuPopupAjax.orderingEnabled === "1") {
            this.saveOrderToDatabase(formData);
          } else {
            alert(
              "Ordering is currently unavailable. Please contact the restaurant directly to place your order."
            );
            return;
          }
        } else {
          // Payment gateway (Fawry or others) - process payment first
          this.processPaymentAndPlaceOrder(formData, selectedPaymentMethod);
        }
      }

      // ------------------------------------------------------------------
      // Order placement. The browser only sends what the customer chose
      // (item id, size, quantity, contact details); the server works out
      // every price, fee and total.
      // ------------------------------------------------------------------

      newRequestId() {
        if (window.crypto && window.crypto.randomUUID) {
          return window.crypto.randomUUID();
        }
        return (
          "r" + Date.now().toString(36) + Math.random().toString(36).slice(2, 12)
        );
      }

      // Cart as plain lines: id, size and quantity
      getCartLines() {
        return Object.entries(this.cart).map(([key, item]) => {
          let id = item.id;
          let size = item.size || "";
          if (!id) {
            // Cart saved by an older version: rebuild from the key (id or id_size)
            const m = String(key).match(/^(\d+)(?:_(.+))?$/);
            id = m ? m[1] : 0;
            size = m && m[2] ? m[2].replace(/_/g, " ") : "";
          }
          return {
            id: id,
            size: size,
            quantity: item.quantity,
            name: item.name,
          };
        });
      }

      // Delivery pin details for the order request (map delivery only)
      deliveryExtras(formData) {
        if (
          formData.order_type === "delivery" &&
          window.mpDelivery &&
          window.mpDelivery.isMapMode()
        ) {
          return window.mpDelivery.extras();
        }
        return {};
      }

      // Send the order to the server. Returns the jQuery request.
      placeOrder(formData, source) {
        if (!this.orderRequestId) {
          this.orderRequestId = this.newRequestId();
        }

        return $.ajax({
          url: menuPopupAjax.ajaxurl,
          type: "POST",
          dataType: "json",
          data: {
            action: "mp_place_order",
            nonce: menuPopupAjax.nonce,
            order: {
              request_id: this.orderRequestId,
              source: source || "online",
              order_type: formData.order_type || "pickup",
              branch_id: menuPopupAjax.activeBranchId || 0,
              customer_name: formData.customer_name || "",
              customer_phone: formData.customer_phone || "",
              customer_email: formData.customer_email || "",
              customer_address: formData.customer_address || "",
              delivery_zone: formData.delivery_zone || "",
              delivery_lat:
                formData.delivery_lat !== undefined ? formData.delivery_lat : "",
              delivery_lng:
                formData.delivery_lng !== undefined ? formData.delivery_lng : "",
              address_details: formData.address_details || "",
              special_instructions: formData.special_instructions || "",
              payment_method: formData.payment_method || "",
              items: this.getCartLines(),
            },
          },
        });
      }

      // Message to show when the server refused the order or was unreachable
      orderErrorMessage(response) {
        if (response && response.data && response.data.message) {
          return response.data.message;
        }
        return "Error connecting to server. Please try again.";
      }

      setOrderButtonsBusy(busy, label) {
        const buttons = $("#order-form .order-form-submit, .confirmation-proceed");
        buttons.each(function () {
          const btn = $(this);
          if (busy) {
            if (btn.data("mp-original-text") === undefined) {
              btn.data("mp-original-text", btn.text());
            }
            btn.text(label).prop("disabled", true);
          } else {
            if (btn.data("mp-original-text") !== undefined) {
              btn.text(btn.data("mp-original-text"));
              btn.removeData("mp-original-text");
            }
            btn.prop("disabled", false);
          }
        });
      }

      // Process payment and place order for payment gateways
      processPaymentAndPlaceOrder(formData, paymentMethod) {
        if (paymentMethod === "fawry") {
          this.createOrderAndProcessPayment(formData, paymentMethod);
        } else {
          // Unknown payment method - fall back to a normal order
          this.saveOrderToDatabase(formData);
        }
      }

      // Create the order on the server, then start the online payment (Fawry)
      createOrderAndProcessPayment(formData, paymentMethod) {
        this.setOrderButtonsBusy(true, "Processing Payment...");
        formData.payment_method = paymentMethod;

        this.placeOrder(formData, "online")
          .done((response) => {
            if (response && response.success) {
              this.processFawryPayment(formData, response.data);
            } else {
              this.orderRequestId = null;
              this.setOrderButtonsBusy(false);
              alert(this.orderErrorMessage(response));
            }
          })
          .fail(() => {
            this.setOrderButtonsBusy(false);
            alert("Error connecting to server. Please try again.");
          });
      }

      // Process Fawry payment for an order that already exists on the server
      processFawryPayment(formData, order) {
        $.ajax({
          url: window.menuPopupPayment.ajax_url,
          type: "POST",
          data: {
            action: "process_payment",
            nonce: window.menuPopupPayment.nonce,
            payment_method: "fawry",
            order_data: {
              order_id: order.order_id,
              order_number: order.order_number,
              order_type: order.order_type,
              customer_name: formData.customer_name,
              customer_phone: formData.customer_phone,
              customer_email: formData.customer_email,
              customer_address: formData.customer_address,
              delivery_zone: formData.delivery_zone,
              special_instructions: formData.special_instructions,
              payment_method: "fawry",
              subtotal: order.subtotal,
              tax: order.tax,
              delivery_fee: order.delivery_fee,
              total: order.total,
            },
          },
          success: (response) => {
            if (response.success && response.data.payment_url) {
              // Redirect to the Fawry payment page
              window.location.href = response.data.payment_url;
            } else if (response.success && response.data.status === "completed") {
              this.setOrderButtonsBusy(false);
              this.showOrderSuccess(order);
            } else {
              this.setOrderButtonsBusy(false);
              alert(
                (response.data && response.data.message) ||
                  "Payment initialization failed. Please try again."
              );
            }
          },
          error: () => {
            this.setOrderButtonsBusy(false);
            alert("Payment error. Please try again.");
          },
        });
      }

      // Success message with the order summary the server calculated
      showOrderSuccess(result) {
        $("#order-form-modal, #order-confirmation-modal, #order-review-modal").removeClass(
          "active"
        );
        this.orderRequestId = null;
        this.clearCart();

        const cur = result.currency_symbol || menuPopupAjax.currencySymbol || "";
        const money = (v) => cur + Number(v || 0).toFixed(2);

        if (!$("#mp-order-success-style").length) {
          $("head").append(
            '<style id="mp-order-success-style">' +
              "#mp-order-success{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:16px}" +
              "#mp-order-success .mp-os-box{background:#fff;color:#222;border-radius:14px;max-width:420px;width:100%;max-height:90vh;overflow:auto;padding:24px;box-shadow:0 12px 40px rgba(0,0,0,.3);text-align:center}" +
              "#mp-order-success h3{margin:0 0 6px;font-size:20px}" +
              "#mp-order-success .mp-os-number{margin:0 0 14px;font-weight:600;color:#555}" +
              "#mp-order-success ul{list-style:none;margin:0 0 12px;padding:0;text-align:start}" +
              "#mp-order-success li{display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px solid #eee}" +
              "#mp-order-success .mp-os-row{display:flex;justify-content:space-between;padding:3px 0;text-align:start}" +
              "#mp-order-success .mp-os-total{font-weight:700;font-size:17px;border-top:2px solid #222;margin-top:6px;padding-top:8px}" +
              "#mp-order-success button{margin-top:16px;padding:10px 28px;border:0;border-radius:8px;background:#222;color:#fff;font-size:15px;cursor:pointer}" +
              "</style>"
          );
        }

        $("#mp-order-success").remove();
        const overlay = $('<div id="mp-order-success"></div>');
        const box = $('<div class="mp-os-box" role="dialog" aria-modal="true" dir="auto"></div>');
        box.append($("<h3></h3>").text("Order placed successfully"));
        box.append($('<p class="mp-os-number"></p>').text("Order #" + result.order_number));

        const list = $("<ul></ul>");
        (result.items || []).forEach((line) => {
          const li = $("<li></li>");
          li.append($("<span></span>").text(line.name + " x" + line.quantity));
          li.append($("<span></span>").text(money(line.line_total)));
          list.append(li);
        });
        box.append(list);

        const row = (label, value, extra) =>
          $('<div class="mp-os-row ' + (extra || "") + '"></div>')
            .append($("<span></span>").text(label))
            .append($("<span></span>").text(value));

        box.append(row("Subtotal", money(result.subtotal)));
        if (Number(result.tax) > 0) {
          box.append(row("Tax", money(result.tax)));
        }
        if (result.order_type === "delivery" && Number(result.delivery_fee) > 0) {
          box.append(row("Delivery Fee", money(result.delivery_fee)));
        }
        box.append(row("Total", money(result.total), "mp-os-total"));
        if (result.order_type === "delivery" && Number(result.delivery_eta) > 0) {
          box.append(row("Estimated delivery", "about " + result.delivery_eta + " min"));
        }

        const close = $('<button type="button"></button>').text("Close");
        const dismiss = () => overlay.remove();
        close.on("click", dismiss);
        overlay.on("click", (e) => {
          if (e.target === overlay[0]) {
            dismiss();
          }
        });
        box.append(close);
        overlay.append(box);
        $("body").append(overlay);
      }

      // Save an online order
      saveOrderToDatabase(formData) {
        this.setOrderButtonsBusy(true, "Processing...");

        this.placeOrder(formData, "online")
          .done((response) => {
            if (response && response.success) {
              this.setOrderButtonsBusy(false);
              this.showOrderSuccess(response.data);
            } else {
              this.orderRequestId = null;
              this.setOrderButtonsBusy(false);
              alert(this.orderErrorMessage(response));
            }
          })
          .fail(() => {
            this.setOrderButtonsBusy(false);
            alert("Error connecting to server. Please try again.");
          });
      }
    }

    // Initialize when DOM is ready
    $(document).ready(() => {
      window.menuPopup = new MenuPopup();

      // A new delivery quote (pin moved, final cart value) updates the open review window
      $(document).on("mp:delivery-quote", () => {
        if ($("#order-review-modal").hasClass("active")) {
          window.menuPopup.renderOrderReview();
        }
      });
    });
  } catch (error) {}
})(jQuery);
