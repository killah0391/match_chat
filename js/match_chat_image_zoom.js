(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.matchChatImageZoom = {
    attach: function (context) {
      const zoomableImages = once('zoomable-image', '.js-zoomable-image', context);

      zoomableImages.forEach(image => {
        image.addEventListener('click', function (e) {
          e.preventDefault();

          // Find all related images in the same container (chat message or gallery)
          const galleryContainer = image.closest('.chat-message-images, .gallery-horizontal-list');
          const galleryImages = galleryContainer ? Array.from(galleryContainer.querySelectorAll('.js-zoomable-image')) : [image];

          const imageSources = galleryImages.map(img => img.dataset.zoomSrc || img.src);
          let currentIndex = galleryImages.indexOf(image);

          // --- Create Modal Elements ---
          const modal = document.createElement('div');
          modal.classList.add('match-chat-image-zoom-modal');

          const closeButton = document.createElement('span');
          closeButton.classList.add('match-chat-image-zoom-close');
          closeButton.innerHTML = '&times;';
          closeButton.setAttribute('aria-label', Drupal.t('Close'));

          const modalImage = document.createElement('img');
          modalImage.classList.add('match-chat-image-zoom-content');
          modalImage.alt = this.alt;

          // Navigation buttons
          const prevButton = document.createElement('a');
          prevButton.classList.add('match-chat-image-zoom-nav', 'match-chat-image-zoom-prev');
          prevButton.innerHTML = '&#10094;';
          prevButton.setAttribute('aria-label', Drupal.t('Previous image'));

          const nextButton = document.createElement('a');
          nextButton.classList.add('match-chat-image-zoom-nav', 'match-chat-image-zoom-next');
          nextButton.innerHTML = '&#10095;';
          nextButton.setAttribute('aria-label', Drupal.t('Next image'));

          // Append elements
          modal.appendChild(closeButton);
          modal.appendChild(modalImage);
          if (imageSources.length > 1) {
            modal.appendChild(prevButton);
            modal.appendChild(nextButton);
          }
          document.body.appendChild(modal);

          // --- Modal Logic ---

          const showImage = (index) => {
            // Fade out, change src, then fade in for a smooth transition
            modalImage.style.opacity = '0';
            setTimeout(() => {
              modalImage.src = imageSources[index];
              modalImage.alt = galleryImages[index].alt;
              modalImage.style.opacity = '1';
            }, 150); // Duration should be less than CSS transition
          };

          const nextImage = () => {
            currentIndex = (currentIndex + 1) % imageSources.length;
            showImage(currentIndex);
          };

          const prevImage = () => {
            currentIndex = (currentIndex - 1 + imageSources.length) % imageSources.length;
            showImage(currentIndex);
          };

          // Initial image display
          showImage(currentIndex);

          // Show the modal
          setTimeout(() => {
            modal.classList.add('is-visible');
          }, 10);

          // --- Event Listeners ---

          const closeModal = () => {
            modal.classList.remove('is-visible');
            modal.addEventListener('transitionend', () => {
              if (modal.parentNode) {
                modal.parentNode.removeChild(modal);
              }
              // IMPORTANT: remove keydown listener to prevent memory leaks
              document.removeEventListener('keydown', keydownListener);
            }, { once: true });
          };

          closeButton.addEventListener('click', closeModal);
          modal.addEventListener('click', (event) => {
            if (event.target === modal) {
              closeModal();
            }
          });

          if (imageSources.length > 1) {
            nextButton.addEventListener('click', nextImage);
            prevButton.addEventListener('click', prevImage);
          }

          const keydownListener = (event) => {
            if (event.key === 'Escape') {
              closeModal();
            }
            if (imageSources.length > 1) {
              if (event.key === 'ArrowRight') {
                nextImage();
              } else if (event.key === 'ArrowLeft') {
                prevImage();
              }
            }
          };
          document.addEventListener('keydown', keydownListener);
        });
      });
    }
  };

})(Drupal, once);