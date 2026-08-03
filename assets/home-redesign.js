/* ZNP HOMEPAGE REDESIGN START */
document.addEventListener('DOMContentLoaded', () => {
  const slider = document.querySelector('.zhr-hero-media');
  const slides = Array.from(slider?.querySelectorAll('.home-slide') || []);
  const captionNumber = document.querySelector('[data-home-caption-number]');
  const captionName = document.querySelector('[data-home-caption-name]');

  if (!slider || !slides.length || !captionNumber || !captionName) {
    return;
  }

  const updateCaption = () => {
    const activeIndex = slides.findIndex(slide => slide.classList.contains('active'));
    const index = activeIndex >= 0 ? activeIndex : 0;
    captionNumber.textContent = String(index + 1).padStart(2, '0');
    captionName.textContent = slides[index].dataset.projectName || 'ZNP Development';
  };

  const observer = new MutationObserver(updateCaption);
  slides.forEach(slide => observer.observe(slide, {
    attributes: true,
    attributeFilter: ['class'],
  }));

  updateCaption();
});
/* ZNP HOMEPAGE REDESIGN END */
