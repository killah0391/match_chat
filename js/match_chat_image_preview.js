((Drupal, once) => {
  /**
   * @file
   * Replaces the standard file list with image previews and handles removal/cleanup.
   *
   * This behavior uses the FileReader API to generate instant, client-side
   * previews to avoid "Access Denied" errors on temporary files. It then
   * syncs with Drupal's AJAX upload to get the File ID for removal and
   * cleans up the previews after a message is sent.
   */

  /**
   * Drupal behavior for enhancing the match message form with image previews.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.matchChatImagePreview = {
    attach(context) {
      const forms = once('match-message-form', 'form.match-chat-message-form', context);
      if (forms.length === 0) {
        return;
      }

      forms.forEach((form) => {
        // Get all essential elements.
        const fileInput = form.querySelector('input[type="file"]');
        const formItem = form.querySelector('.js-form-item-chat-images');
        const ajaxWrapper = formItem ? formItem.closest('[id^="ajax-wrapper"], .js-form-managed-file').parentElement : null;
        const previewContainer = form.querySelector('[id^="match-chat-image-previews-"]');
        const removedInput = form.querySelector('[id^="edit-images-to-remove-"]');

        if (!fileInput || !ajaxWrapper || !previewContainer || !removedInput) {
          return;
        }

        /**
         * Adds a file ID to the hidden input for removal on form submission.
         */
        const updateRemovedFids = (fid) => {
          const currentFids = removedInput.value ? removedInput.value.split(',') : [];
          if (!currentFids.includes(fid)) {
            currentFids.push(fid);
            removedInput.value = currentFids.join(',');
          }
        };

        /**
         * Activates a placeholder preview by pairing it with a newly uploaded file from Drupal.
         */
        const activatePreview = (previewItem, fileSpan, fid) => {
          // Mark the preview as activated with the official FID.
          previewItem.dataset.fid = fid;

          // Add the remove button now that we have the FID.
          const removeButton = document.createElement('button');
          removeButton.type = 'button';
          removeButton.classList.add('image-preview-remove');
          removeButton.setAttribute('aria-label', Drupal.t('Remove this image'));
          removeButton.innerHTML = '&times;';
          removeButton.addEventListener('click', (e) => {
            e.preventDefault();
            updateRemovedFids(fid);
            previewItem.remove();
          });
          previewItem.appendChild(removeButton);

          // Hide the original Drupal file list item.
          fileSpan.style.display = 'none';
          const originalRemoveButton = fileSpan.nextElementSibling;
          if (originalRemoveButton && originalRemoveButton.matches('button[name*="chat_images_remove_button"]')) {
            originalRemoveButton.style.display = 'none';
          }
        };

        /**
         * Scans the AJAX wrapper for newly uploaded files and pairs them with placeholder previews.
         */
        const processUploadedFiles = () => {
          const fileSpans = ajaxWrapper.querySelectorAll('span.file[data-drupal-selector*="file-"]');

          fileSpans.forEach((fileSpan) => {
            const fidMatch = fileSpan.dataset.drupalSelector.match(/file-(\d+)-/);
            if (!fidMatch) return;
            const fid = fidMatch[1];

            // If a preview for this FID already exists, it's already been processed.
            if (previewContainer.querySelector(`[data-fid="${fid}"]`)) {
              return;
            }

            // Find the first placeholder preview that is still waiting for a FID.
            const unactivatedPreview = previewContainer.querySelector('.image-preview-item:not([data-fid])');

            if (unactivatedPreview) {
              activatePreview(unactivatedPreview, fileSpan, fid);
            }
          });
        };

        /**
         * Creates an instant, client-side placeholder preview for a selected file.
         */
        const createPlaceholderPreview = (file) => {
          const reader = new FileReader();
          reader.onload = (e) => {
            const previewItem = document.createElement('div');
            previewItem.classList.add('image-preview-item', 'is-loading');

            const img = document.createElement('img');
            img.src = e.target.result;
            img.alt = Drupal.t('Image preview for @filename', { '@filename': file.name });

            previewItem.appendChild(img);
            previewContainer.appendChild(previewItem);
          };
          reader.readAsDataURL(file);
        };

        // Use `once` to attach the change listener to the file input.
        once('file-input-listener', fileInput).forEach((input) => {
          input.addEventListener('change', () => {
            if (input.files.length) {
              Array.from(input.files).forEach(createPlaceholderPreview);
            }
          });
        });

        // The user confirmed this MutationObserver pattern works for detecting the AJAX change.
        const observer = new MutationObserver(() => {
          processUploadedFiles();
        });

        // Initial run for any files already present (e.g., on form validation error).
        processUploadedFiles();

        // Start observing the wrapper for changes.
        observer.observe(ajaxWrapper, { childList: true, subtree: true });
      });
    },
  };
})(Drupal, once);