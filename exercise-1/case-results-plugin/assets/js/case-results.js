/**
 * Case Results – Frontend JavaScript
 *
 * Responsibilities:
 *  1. Filter cases by type via AJAX (no page reload).
 *  2. Fire a GTM 'case_result_view' event when a user clicks a case card.
 *  3. Handle pagination within filtered results.
 *
 *  - Variables declared inside are private — no global namespace pollution.
 */

/* global CaseResultsData, dataLayer */

(function ($) {
  'use strict';

  // -------------------------------------------------------------------------
  // State
  // -------------------------------------------------------------------------
  var state = {
    currentType : 'all',  // Active filter value.
    currentPage : 1,      // Active pagination page.
    isLoading   : false,  // Prevent concurrent requests.
  };

  // -------------------------------------------------------------------------
  // Cached DOM references — read once, reuse many times.
  // -------------------------------------------------------------------------
  var $grid       = $('#cr-results-grid');
  var $shortcode  = $('#cr-shortcode-grid');
  var $pagination = $('#cr-pagination');
  var $filterBtns = $('.cr-filter-btn');

  // -------------------------------------------------------------------------
  // 1. Filter buttons
  // -------------------------------------------------------------------------

  $filterBtns.on('click', function () {
    var $btn     = $(this);
    var caseType = $btn.data('type');

    // No-op if already active.
    if (caseType === state.currentType) {
      return;
    }

    // Update active button state for visual feedback AND accessibility.
    $filterBtns.removeClass('active').attr('aria-pressed', 'false');
    $btn.addClass('active').attr('aria-pressed', 'true');

    // Reset to page 1 on every new filter — makes sense UX-wise.
    state.currentType = caseType;
    state.currentPage = 1;

    fetchResults();
  });

  // -------------------------------------------------------------------------
  // 2. Pagination — delegated on #cr-pagination so it works after AJAX swaps.
  // -------------------------------------------------------------------------

  /**
   * WHY event delegation?
   * After an AJAX response, the pagination HTML inside #cr-pagination is
   * replaced.  Direct .on('click') bindings on the old elements would be
   * lost.  Delegating to a stable parent (#cr-pagination) means the handler
   * always fires regardless of how many times the inner HTML changes.
   */
  $pagination.on('click', 'a', function (e) {
    e.preventDefault();

    var href  = $(this).attr('href');
    var match = href ? href.match(/\/page\/(\d+)/) : null;
    var page  = match ? parseInt(match[1], 10) : 1;

    state.currentPage = page;
    fetchResults();

    // Scroll to the grid top so the user doesn't need to scroll back up.
    $('html, body').animate({ scrollTop: $grid.offset().top - 60 }, 300);
  });

  // -------------------------------------------------------------------------
  // 3. Core AJAX function
  // -------------------------------------------------------------------------

  /**
   * Send a POST request to admin-ajax.php and update the DOM with the response.
   */
  function fetchResults() {
    if (state.isLoading) {
      return; // Prevent double-firing from rapid clicks.
    }

    $.ajax({
      url    : CaseResultsData.ajaxUrl,
      type   : 'POST',
      data   : {
        action    : CaseResultsData.action,
        nonce     : CaseResultsData.nonce,     // Security: nonce verified server-side.
        case_type : state.currentType,
        paged     : state.currentPage,
      },

      success: function (response) {
        if (response.success) {
          // Replace grid HTML with fresh server-rendered cards.
          $grid.html(response.data.html);
          $shortcode.html(response.data.html);
          // Update pagination links.
          updatePagination(response.data);
        } else {
          showError(CaseResultsData.i18n.error);
        }
      },

      error: function (xhr, status, error) {
        // Log for debugging; don't expose raw errors to users.
        console.error('[Case Results] AJAX error:', status, error);
        showError(CaseResultsData.i18n.error);
      },

      complete: function () {

      },
    });
  }

  // -------------------------------------------------------------------------
  // 4. GTM Event Tracking
  // -------------------------------------------------------------------------

  /**
   * Delegate click tracking to the results grid so it works after AJAX swaps.
   *
   *
   * Parameters tracked:
   *  - case_type:        the legal category (e.g. 'car_accident').
   *  - settlement_amount: the raw integer value for numeric reporting.
   *  - post_id:          useful for deduplying events in GA4.
   */
  $grid.on('click', '.cr-card-link', function (e) {
    var $card      = $(this).closest('.cr-card');
    var caseType   = $card.data('case-type')  || '';
    var settlement = $card.data('settlement') || 0;
    var postId     = $card.data('post-id')    || 0;
    console.log('GTM EVENT FIRED', {
  case_type: caseType,
  settlement: settlement,
  post_id: postId
});
    // Guard: only push if GTM dataLayer exists (some pages may not have GTM).
    if (typeof dataLayer !== 'undefined') {
      dataLayer.push({
        event            : 'case_result_view',
        case_type        : caseType,
        settlement_amount: parseInt(settlement, 10),
        post_id          : parseInt(postId, 10),
      });
    }

    // Note: we do NOT call e.preventDefault() here — we want the link to
    // navigate normally.  GTM will handle any beacon/async tracking.
    // If we needed to wait for the beacon before navigating, we'd use
    // eventCallback inside the dataLayer.push object.
  });

  // -------------------------------------------------------------------------
  // 5. Helper functions
  // -------------------------------------------------------------------------

  /** Display an error message inside the grid. */
  function showError(message) {
    $grid.html('<p class="cr-error-message">' + $('<span>').text(message).html() + '</p>');
  }

  /**
   * Rebuild pagination based on AJAX response data.
   * We generate simple prev/next/numbered links rather than depending on
   * WordPress's paginate_links() (which runs server-side only).
   */
  function updatePagination(data) {
    var total   = data.max_num_pages;
    var current = data.current_page;

    if (total <= 1) {
      $pagination.empty();
      return;
    }

    var html = '<nav class="cr-pagination-nav" aria-label="' + escAttr(CaseResultsData.i18n.pagination || 'Pagination') + '">';
    html += '<ul class="cr-page-numbers">';

    for (var i = 1; i <= total; i++) {
      if (i === current) {
        html += '<li><span class="cr-page-current" aria-current="page">' + i + '</span></li>';
      } else {
        // href is just a placeholder — JS intercepts before navigation.
        html += '<li><a href="/case-results/page/' + i + '/" class="cr-page-link">' + i + '</a></li>';
      }
    }

    html += '</ul></nav>';
    $pagination.html(html);
  }

  /** Minimal XSS-safe attribute escaping for dynamic HTML. */
  function escAttr(str) {
    return $('<span>').attr('title', str).prop('outerHTML').match(/title="([^"]*)"/)[1];
  }

}(jQuery));
