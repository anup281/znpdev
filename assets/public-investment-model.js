(() => {
  'use strict';
  const dialog = document.querySelector('[data-public-investment-model]');
  if (!dialog) return;

  const amountSelect = dialog.querySelector('[data-public-investment-amount]');
  const results = dialog.querySelector('[data-public-model-results]');
  const money = new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 0
  });
  let projectId = '';

  const projectActivity = (year, yearNumber) => {
    if (yearNumber === 1) return 'Construction and Operations';
    if (year?.hasRefinance) return 'Refinance';
    if (year?.hasSale) return 'Selling';
    return 'Operations';
  };

  function periodicIrr(cashFlows, periodsPerYear = 1) {
    if (!cashFlows.some(value => value < 0) || !cashFlows.some(value => value > 0)) return null;
    const npv = rate => cashFlows.reduce((total, value, index) => total + value / Math.pow(1 + rate, index), 0);
    let low = -.9999;
    let high = 100;
    if (npv(low) * npv(high) > 0) return null;
    for (let index = 0; index < 180; index += 1) {
      const middle = (low + high) / 2;
      if (npv(low) * npv(middle) <= 0) high = middle;
      else low = middle;
    }
    const periodicRate = (low + high) / 2;
    return (Math.pow(1 + periodicRate, periodsPerYear) - 1) * 100;
  }

  function renderProjection() {
    const projection = window.znpInvestmentProjections?.[projectId];
    const engine = Array.from(document.querySelectorAll('[data-waterfall-projection-engine]'))
      .find(candidate => candidate.dataset.projectId === projectId);
    if (!projection || !engine) {
      results.innerHTML = '<div class="public-investment-model-error">This project projection is temporarily unavailable.</div>';
      return;
    }
    let settings = {};
    try {
      settings = JSON.parse(engine.dataset.modelSettings || '{}');
    } catch (error) {}
    const investment = Number(amountSelect.value);
    const totalUnits = Math.max(1, Number(settings.lp_total_units) || Number(projection.a.lpEquity) || 1);
    const ownership = investment / totalUnits;
    const annualByYear = new Map(projection.annual.map(year => [Number(year.year), year]));
    let cumulativeDistributions = 0;
    const annualDistributions = [];
    let rows = '';

    const projectionYears = Math.max(1, Math.min(10, Math.ceil(Number(projection.a.holdYears) || 1)));
    for (let yearNumber = 1; yearNumber <= projectionYears; yearNumber += 1) {
      const year = annualByYear.get(yearNumber);
      const totalDistribution = Number(year?.investorDistributions || 0) * ownership;
      annualDistributions.push(totalDistribution);
      cumulativeDistributions += totalDistribution;
      rows += '<article class="public-investment-year-card"><strong>Year ' + yearNumber +
        '</strong><span>Projected Distribution: <b>' + money.format(totalDistribution) +
        '</b></span><em>' + projectActivity(year, yearNumber) + '</em></article>';
    }

    const equityMultiple = investment > 0 ? cumulativeDistributions / investment : 0;
    const lpMonthlyDistributions = projection.investorMetrics?.lpDistributions ||
      projection.monthly.map(month => Number(month.lpDistribution || 0));
    const cashFlowsLP = [-investment, ...lpMonthlyDistributions.map(distribution => Number(distribution) * ownership)];
    const exitIrr = periodicIrr(cashFlowsLP, 12);
    const lpPreferredReturn = projection.monthly.reduce((total, month) => total + Number(month.preferredReturnPaid || 0) * ownership, 0);
    const lpCapitalReturned = projection.monthly.reduce((total, month) => total + Number(month.returnOfCapital || 0) * ownership, 0);
    const lpProfitDistribution = projection.monthly.reduce((total, month) => total + Number(month.investorProfitDistribution || 0) * ownership, 0);
    results.innerHTML =
      '<div class="public-investment-model-metrics"><article><span>Preferred Return</span><strong>' +
      money.format(lpPreferredReturn) + '</strong></article><article><span>Capital Returned</span><strong>' +
      money.format(lpCapitalReturned) + '</strong></article><article><span>LP Profit Distribution</span><strong>' +
      money.format(lpProfitDistribution) + '</strong></article><article><span>Total LP Distribution</span><strong>' +
      money.format(cumulativeDistributions) + '</strong></article><article><span>Projected LP IRR</span><strong>' +
      (exitIrr === null ? 'N/A' : exitIrr.toFixed(2) + '%') + '</strong></article><article><span>Equity Multiple</span><strong>' +
      equityMultiple.toFixed(2) + '×</strong></article></div><div class="public-investment-year-list" aria-label="Year-by-year investment projection">' +
      rows + '</div>';
  }

  function openModel(button) {
    projectId = button.dataset.openPublicModel;
    dialog.querySelector('[data-public-model-title]').textContent = button.dataset.projectName + ' Project Model';
    amountSelect.value = '25000';
    renderProjection();
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
  }

  function closeModel() {
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  }

  document.addEventListener('click', event => {
    const open = event.target.closest('[data-open-public-model]');
    if (open) {
      openModel(open);
      return;
    }
    if (event.target.closest('[data-close-public-model]')) closeModel();
  });
  amountSelect.addEventListener('change', renderProjection);
  dialog.addEventListener('click', event => {
    if (event.target === dialog) closeModel();
  });
})();
