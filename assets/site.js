
document.addEventListener('DOMContentLoaded', function () {
  const menuButton = document.querySelector('.menu-btn');
  const mobileMenu = document.querySelector('.mobile-menu');

  if (menuButton && mobileMenu) {
    const closeMenu = () => {
      mobileMenu.classList.remove('open');
      menuButton.setAttribute('aria-expanded','false');
    };
    menuButton.addEventListener('click', function (event) {
      event.stopPropagation();
      const isOpen = mobileMenu.classList.toggle('open');
      menuButton.setAttribute('aria-expanded', String(isOpen));
    });
    mobileMenu.addEventListener('click', e => e.stopPropagation());
    document.addEventListener('click', closeMenu);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMenu(); });
  }

  const homeSlides = Array.from(document.querySelectorAll('.home-slide'));
  if (homeSlides.length) {
    let current = 0;
    const slider = homeSlides[0].closest('.home-slider');
    const interval = Math.max(2000, Number(slider?.dataset.slideInterval || 4000));
    const activate = index => {
      const next = homeSlides[index];
      homeSlides[current].classList.remove('active');
      current = index;
      next.classList.add('active');
    };
    if (homeSlides.length > 1) {
      setInterval(() => activate((current + 1) % homeSlides.length), interval);
    }
  }

  document.querySelectorAll('.category-slider').forEach(slider => {
    const slides = Array.from(slider.querySelectorAll('.category-slide'));
    const dots = Array.from(slider.querySelectorAll('.category-dot'));
    const tabs = Array.from(slider.querySelectorAll('.category-tab'));
    const prev = slider.querySelector('.slider-arrow.prev');
    const next = slider.querySelector('.slider-arrow.next');
    let current = 0;
    let timer;

    const show = index => {
      current = (index + slides.length) % slides.length;
      slides.forEach((slide,i) => slide.classList.toggle('active', i === current));
      dots.forEach((dot,i) => dot.classList.toggle('active', i === current));
      tabs.forEach((tab,i) => tab.classList.toggle('active', i === current));
    };
    const start = () => {
      clearInterval(timer);
      timer = setInterval(() => show(current + 1), 6000);
    };

    if (prev) prev.addEventListener('click', () => { show(current - 1); start(); });
    if (next) next.addEventListener('click', () => { show(current + 1); start(); });
    dots.forEach((dot,i) => dot.addEventListener('click', () => { show(i); start(); }));
    tabs.forEach((tab,i) => tab.addEventListener('click', () => { show(i); start(); }));

    slider.addEventListener('mouseenter', () => clearInterval(timer));
    slider.addEventListener('mouseleave', start);
    show(0);
    start();
  });
});

document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('.portfolio-card-button').forEach(button=>{button.addEventListener('click',function(){const project=button.closest('.portfolio-project');const section=project.closest('.portfolio-section');const wasOpen=project.classList.contains('open');section.querySelectorAll('.portfolio-project.open').forEach(p=>{p.classList.remove('open');const b=p.querySelector('.portfolio-card-button');b.setAttribute('aria-expanded','false');b.querySelector('.portfolio-open-label').textContent='View Details →'});if(!wasOpen){project.classList.add('open');button.setAttribute('aria-expanded','true');button.querySelector('.portfolio-open-label').textContent='Hide Details ↑';setTimeout(()=>project.scrollIntoView({behavior:'smooth',block:'center'}),150)}})})});


