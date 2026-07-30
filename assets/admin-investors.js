(() => {
  'use strict';
  const root = document.querySelector('[data-investor-structure]');
  if (!root) return;

  const dialog = root.querySelector('[data-investor-dialog]');
  const projectionDialog = root.querySelector('[data-investor-projection-dialog]');
  const projectionContent = root.querySelector('[data-projection-content]');
  const form = root.querySelector('[data-investor-form]');
  const alert = root.querySelector('[data-investor-alert]');
  const formError = root.querySelector('[data-investor-form-error]');
  const readOnly = root.dataset.readOnly === '1';
  const money = new Intl.NumberFormat('en-US', {style:'currency',currency:'USD',maximumFractionDigits:2});
  let state = null;
  let busy = false;

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, character => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
  })[character]);

  function showAlert(message, type = 'error') {
    alert.hidden = !message;
    alert.className = 'admin-investor-alert ' + type;
    alert.textContent = message || '';
  }

  async function request(action, values = {}) {
    const data = new FormData();
    data.set('csrf_token', root.dataset.csrf);
    data.set('investment_opportunity_id', root.dataset.projectId);
    data.set('action', action);
    Object.entries(values).forEach(([key, value]) => data.set(key, value));
    const response = await fetch('investment_investors_ajax.php', {
      method: 'POST',
      body: data,
      credentials: 'same-origin'
    });
    const payload = await response.json().catch(() => ({ok:false,error:'The server returned an invalid response.'}));
    if (!response.ok || !payload.ok) throw new Error(payload.error || 'The investor structure could not be updated.');
    return payload;
  }

  function investorCard(investor, type, lpTotalUnits = 0) {
    const name = escapeHtml(investor.first_name + ' ' + investor.last_name);
    const lpOwnership = lpTotalUnits > 0
      ? (Number(investor.unit_amount) / lpTotalUnits) * 100
      : 0;
    const detail = type === 'GP'
      ? '<strong>' + Number(investor.ownership_percent).toFixed(2) + '%</strong><span>' + money.format(investor.investment_amount) + ' invested</span>'
      : '<strong>' + money.format(investor.unit_amount) + '</strong><span>units subscribed</span>' +
        '<span class="admin-investor-ownership-share">' + lpOwnership.toFixed(2) + '% of total LP units</span>';
    return '<article class="admin-investor-person"><div><span class="admin-investor-avatar">' +
      escapeHtml(investor.first_name.charAt(0) + investor.last_name.charAt(0)) +
      '</span><div><h4><button type="button" data-view-investor="' + investor.id +
      '" data-investor-type="' + type + '">' + name + '</button></h4>' + detail + '</div></div>' +
      (readOnly ? '' : '<div class="admin-investor-person-actions"><button type="button" data-edit-investor="' + investor.id +
      '" data-investor-type="' + type + '">Edit</button><button type="button" class="admin-investor-delete" data-delete-investor="' + investor.id +
      '" data-investor-name="' + name + '" aria-label="Delete ' + name + '">Delete</button></div>') + '</article>';
  }

  function render(data) {
    state = data;
    const summary = data.summary;
    root.querySelector('[data-gp-percent]').textContent = Number(summary.gp_percent).toFixed(2) + '%';
    root.querySelector('[data-gp-remaining]').textContent = Number(summary.gp_percent_remaining).toFixed(2) + '%';
    root.querySelector('[data-lp-subscribed]').textContent = money.format(summary.lp_units_subscribed);
    root.querySelector('[data-lp-remaining]').textContent = money.format(summary.lp_units_remaining);
    root.querySelector('[data-gp-progress]').style.width = Math.min(100, Number(summary.gp_percent)) + '%';
    root.querySelector('[data-lp-total-units]').value = Number(summary.lp_total_units).toFixed(2);
    root.querySelector('[data-gp-list]').innerHTML = data.gp.length
      ? data.gp.map(investor => investorCard(investor, 'GP')).join('')
      : '<div class="admin-investor-empty"><strong>No GP members added</strong><span>' + (readOnly ? 'No GP ownership has been recorded.' : 'Use Add GP to begin allocating ownership.') + '</span></div>';
    root.querySelector('[data-lp-list]').innerHTML = data.lp.length
      ? data.lp.map(investor => investorCard(
          investor,
          'LP',
          Number(summary.lp_total_units)
        )).join('')
      : '<div class="admin-investor-empty"><strong>No LP subscriptions added</strong><span>' + (readOnly ? 'No LP subscriptions have been recorded.' : 'Use Add LP to record the first subscription.') + '</span></div>';
  }

  async function load() {
    showAlert('');
    try {
      const payload = await request('list');
      render(payload.data);
    } catch (error) {
      showAlert(error.message);
      root.querySelector('[data-gp-list]').innerHTML = '<div class="admin-investor-empty">Investor structure unavailable.</div>';
      root.querySelector('[data-lp-list]').innerHTML = '<div class="admin-investor-empty">Investor structure unavailable.</div>';
    }
  }

  function projectionEvent(year) {
    const events = [];
    if (year.hasRefinance) events.push('Refinance');
    if (year.hasSale) events.push('Sale');
    return events.length ? events.join(' + ') : 'Operations';
  }

  function periodicIrr(cashFlows, periodsPerYear = 1) {
    if (!cashFlows.some(value => value < 0) || !cashFlows.some(value => value > 0)) return null;
    const npv = rate => cashFlows.reduce(
      (total, value, index) => total + value / Math.pow(1 + rate, index),
      0
    );
    let low = -0.9999;
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

  function openProjection(type, investor) {
    const model = window.znpInvestmentProjection;
    const summary = state?.summary;
    if (!model || !summary) {
      showAlert('The investment projection is still loading. Try the investor name again.');
      return;
    }
    const name = escapeHtml(investor.first_name + ' ' + investor.last_name);
    root.querySelector('[data-projection-eyebrow]').textContent = type === 'GP' ? 'GP Promote Plan' : 'LP Investment Plan';
    root.querySelector('[data-projection-title]').textContent = investor.first_name + ' ' + investor.last_name;
    const annualByYear = new Map(model.annual.map(year => [Number(year.year), year]));
    const projectionYears = Math.max(1, Math.min(10, Math.ceil(Number(model.a.holdYears) || 1)));
    let rows = '';
    let cumulative = 0;
    if (type === 'GP') {
      const share = Number(investor.ownership_percent) / 100;
      root.querySelector('[data-projection-subtitle]').textContent =
        Number(investor.ownership_percent).toFixed(2) + '% of annual GP Promote distributions';
      for (let yearNumber = 1; yearNumber <= projectionYears; yearNumber += 1) {
        const year = annualByYear.get(yearNumber);
        const projectPromote = Number(year?.gpPromote || 0);
        const investorPromote = projectPromote * share;
        cumulative += investorPromote;
        rows += '<tr><th>Year ' + yearNumber + '</th><td>' + escapeHtml(year ? projectionEvent(year) : 'Post-hold') +
          '</td><td>' + money.format(projectPromote) + '</td><td>' + money.format(investorPromote) +
          '</td><td>' + money.format(cumulative) + '</td></tr>';
      }
      projectionContent.innerHTML =
        '<div class="admin-investor-projection-metrics"><article><span>GP Ownership</span><strong>' +
        Number(investor.ownership_percent).toFixed(2) + '%</strong></article><article><span>Recorded Investment</span><strong>' +
        money.format(investor.investment_amount) + '</strong></article><article><span>Projected GP Promote</span><strong>' +
        money.format(cumulative) + '</strong></article></div><div class="admin-investor-projection-table-wrap"><table><thead><tr><th>Year</th><th>Project Activity</th><th>Total GP Promote</th><th>Your GP Promote</th><th>Cumulative Promote</th></tr></thead><tbody>' +
        rows + '</tbody></table></div><p class="admin-investor-projection-note">GP projections include promote distributions only and use the project’s current waterfall assumptions.</p>';
    } else {
      const units = Number(investor.unit_amount);
      const totalUnits = Number(summary.lp_total_units);
      const fullShare = totalUnits > 0 ? units / totalUnits : 0;
      const annualDistributions = Array.from(
        {length: projectionYears},
        (_, index) => Number(annualByYear.get(index + 1)?.investorDistributions || 0) * fullShare
      );
      const lpMonthlyDistributions = model.investorMetrics?.lpDistributions ||
        model.monthly.map(month => Number(month.lpDistribution || 0));
      const cashFlowsLP = [-units, ...lpMonthlyDistributions.map(distribution => Number(distribution) * fullShare)];
      const projectedExitIrr = periodicIrr(cashFlowsLP, 12);
      let remainingCapital = units;
      root.querySelector('[data-projection-subtitle]').textContent =
        money.format(units) + ' invested · ' + (fullShare * 100).toFixed(2) + '% of total LP units';
      for (let yearNumber = 1; yearNumber <= projectionYears; yearNumber += 1) {
        const year = annualByYear.get(yearNumber);
        const investorDistribution = annualDistributions[yearNumber - 1];
        const projectBeginningCapital = Number(year?.beginningInvestorCapital || 0);
        const projectCapitalReturnRate = projectBeginningCapital > 0
          ? Math.min(1, Number(year?.capitalReturned || 0) / projectBeginningCapital)
          : 0;
        const capitalReturned = Math.min(remainingCapital, remainingCapital * projectCapitalReturnRate);
        remainingCapital = Math.max(0, remainingCapital - capitalReturned);
        cumulative += investorDistribution;
        rows += '<tr><th>Year ' + yearNumber + '</th><td>' + escapeHtml(year ? projectionEvent(year) : 'Post-hold') +
          '</td><td>' + money.format(investorDistribution) + '</td><td>' + money.format(capitalReturned) + '</td><td>' + money.format(remainingCapital) +
          '</td><td>' + money.format(cumulative) + '</td></tr>';
      }
      const irrDistributionTotal = cashFlowsLP.slice(1)
        .filter(distribution => distribution > 0)
        .reduce((total, distribution) => total + distribution, 0);
      const irrReconciles = Math.abs(irrDistributionTotal - cumulative) < 0.01;
      projectionContent.innerHTML =
        '<div class="admin-investor-projection-metrics"><article><span>LP Investment</span><strong>' + money.format(units) +
        '</strong></article><article><span>Full-Raise Ownership</span><strong>' + (fullShare * 100).toFixed(2) +
        '%</strong></article><article><span>Projected Distributions</span><strong>' + money.format(cumulative) +
        '</strong></article><article><span>Projected LP IRR at Exit</span><strong>' +
        (projectedExitIrr === null ? 'N/A' : projectedExitIrr.toFixed(2) + '%') +
        '</strong></article></div><div class="admin-investor-projection-table-wrap"><table><thead><tr><th>Year</th><th>Project Activity</th><th>Your Distribution</th><th>Capital Returned</th><th>Unreturned Capital</th><th>Cumulative Distribution</th></tr></thead><tbody>' +
        rows + '</tbody></table></div>' +
        (irrReconciles ? '' : '<p class="admin-investor-projection-note" role="alert">IRR validation warning: annual positive cash flows do not reconcile to projected distributions.</p>') +
        '<p class="admin-investor-projection-note">IRR uses the initial LP investment followed by the investor’s full-precision monthly LP distributions. LP preferred return, capital returned, and profit distributions are included once; GP promote is excluded.</p>';
    }
    if (typeof projectionDialog.showModal === 'function') projectionDialog.showModal();
    else projectionDialog.setAttribute('open', '');
  }

  function closeProjection() {
    if (typeof projectionDialog.close === 'function') projectionDialog.close();
    else projectionDialog.removeAttribute('open');
  }

  function openDialog(type, investor = null) {
    form.reset();
    formError.hidden = true;
    formError.textContent = '';
    root.querySelector('[data-investor-type]').value = type;
    root.querySelector('[data-investor-id]').value = investor ? investor.id : '';
    root.querySelector('[data-dialog-eyebrow]').textContent = type === 'GP' ? 'General Partner' : 'Limited Partner';
    root.querySelector('[data-dialog-title]').textContent = investor
      ? (type === 'GP' ? 'Edit GP Member' : 'Edit LP Subscription')
      : (type === 'GP' ? 'Add GP Member' : 'Add LP Subscription');
    root.querySelector('[data-submit-investor]').textContent = investor ? 'Save Changes' : (type === 'GP' ? 'Add GP' : 'Add LP');
    root.querySelectorAll('[data-gp-field]').forEach(field => {
      field.hidden = type !== 'GP';
      field.querySelector('input').required = type === 'GP';
    });
    root.querySelectorAll('[data-lp-field]').forEach(field => {
      field.hidden = type !== 'LP';
      field.querySelector('input').required = type === 'LP';
    });
    if (investor) {
      form.elements.first_name.value = investor.first_name;
      form.elements.last_name.value = investor.last_name;
      if (type === 'GP') {
        form.elements.ownership_percent.value = investor.ownership_percent;
        form.elements.investment_amount.value = investor.investment_amount;
      } else {
        form.elements.unit_amount.value = investor.unit_amount;
      }
    }
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
    form.querySelector('[name="first_name"]').focus();
  }

  function closeDialog() {
    if (typeof dialog.close === 'function') dialog.close();
    else dialog.removeAttribute('open');
  }

  root.addEventListener('click', async event => {
    const view = event.target.closest('[data-view-investor]');
    if (view) {
      const collection = view.dataset.investorType === 'GP' ? state?.gp : state?.lp;
      const investor = collection?.find(item => item.id === Number(view.dataset.viewInvestor));
      if (investor) openProjection(view.dataset.investorType, investor);
      return;
    }
    if (event.target.closest('[data-close-projection]')) {
      closeProjection();
      return;
    }
    const add = event.target.closest('[data-add-investor]');
    if (add) {
      openDialog(add.dataset.addInvestor);
      return;
    }
    const edit = event.target.closest('[data-edit-investor]');
    if (edit) {
      const collection = edit.dataset.investorType === 'GP' ? state?.gp : state?.lp;
      const investor = collection?.find(item => item.id === Number(edit.dataset.editInvestor));
      if (investor) openDialog(edit.dataset.investorType, investor);
      return;
    }
    if (event.target.closest('[data-close-investor]')) {
      closeDialog();
      return;
    }
    const remove = event.target.closest('[data-delete-investor]');
    if (remove && !busy) {
      if (!window.confirm('Delete ' + remove.dataset.investorName + ' from this project structure?')) return;
      busy = true;
      remove.disabled = true;
      try {
        const payload = await request('delete', {investor_id: remove.dataset.deleteInvestor});
        render(payload.data);
        if (window.adminToast) window.adminToast('Investor removed.', 'success');
      } catch (error) {
        showAlert(error.message);
      } finally {
        busy = false;
      }
      return;
    }
    const saveUnits = event.target.closest('[data-save-units]');
    if (saveUnits && !busy) {
      busy = true;
      saveUnits.disabled = true;
      const status = root.querySelector('[data-unit-status]');
      status.textContent = 'Saving…';
      try {
        const payload = await request('update_units', {lp_total_units: root.querySelector('[data-lp-total-units]').value});
        render(payload.data);
        status.textContent = 'Saved';
        if (window.adminToast) window.adminToast('LP units updated.', 'success');
      } catch (error) {
        status.textContent = error.message;
      } finally {
        busy = false;
        saveUnits.disabled = false;
      }
    }
  });

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || !form.reportValidity()) return;
    busy = true;
    const submit = root.querySelector('[data-submit-investor]');
    submit.disabled = true;
    formError.hidden = true;
    const type = root.querySelector('[data-investor-type]').value;
    const investorId = root.querySelector('[data-investor-id]').value;
    const values = {
      first_name: form.elements.first_name.value,
      last_name: form.elements.last_name.value
    };
    if (type === 'GP') {
      values.ownership_percent = form.elements.ownership_percent.value;
      values.investment_amount = form.elements.investment_amount.value;
    } else {
      values.unit_amount = form.elements.unit_amount.value;
    }
    if (investorId) values.investor_id = investorId;
    try {
      const payload = await request(investorId ? 'update' : (type === 'GP' ? 'add_gp' : 'add_lp'), values);
      render(payload.data);
      closeDialog();
      if (window.adminToast) window.adminToast(investorId ? 'Investor updated.' : type + ' investor added.', 'success');
    } catch (error) {
      formError.textContent = error.message;
      formError.hidden = false;
    } finally {
      busy = false;
      submit.disabled = false;
    }
  });

  dialog.addEventListener('click', event => {
    if (event.target === dialog) closeDialog();
  });
  projectionDialog.addEventListener('click', event => {
    if (event.target === projectionDialog) closeProjection();
  });

  load();
})();
