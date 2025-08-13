jQuery(function($){
  // Quick Edit handler for User List tab
  $(document).on('click', '.rum-quick-edit', function(e){
    e.preventDefault();
    const userId = $(this).data('user-id');
    startInlineEdit($(this).closest('tr'), userId);
  });

  function startInlineEdit($row, userId){
    if($row.data('rum-editing')) return;
    $row.data('rum-editing', true);

    const nonce = $('#rum_export_nonce').val() || (window.arc_dashboard_vars ? window.arc_dashboard_vars.nonce : '');
    const original = {
      role: $row.find('td.column-role').html(),
      parent: $row.find('td.column-parent').html(),
      program: $row.find('td.column-program').html(),
      site: $row.find('td.column-site').html(),
      actions: $row.find('td.column-actions').html()
    };
    $row.data('rum-original', original);

    $.when(
      $.post(ajaxurl, { action:'rum_admin_get_options', nonce }),
      $.post(ajaxurl, { action:'rum_admin_get_user', user_id: userId, nonce })
    ).done(function(optsResp, userResp){
      const opts = (optsResp && optsResp[0] && optsResp[0].success) ? optsResp[0].data : {roles:{}, parents:[], programs:[]};
      const usr = (userResp && userResp[0] && userResp[0].success) ? userResp[0].data : null;
      if(!usr){ restore(); return; }

      // Build controls
      const roleSelect = $('<select id="rum-ie-role"/>');
      Object.keys(opts.roles).forEach(function(k){ roleSelect.append($('<option/>',{value:k,text:opts.roles[k]})); });
      roleSelect.val(usr.role);

      const parentSelect = $('<select id="rum-ie-parent"/>');
      parentSelect.append($('<option/>',{value:0,text:'-'}));
      opts.parents.forEach(function(p){ parentSelect.append($('<option/>',{value:p.id,text:p.name+' ('+p.role+')'})); });
      parentSelect.val(usr.parent);

      const programSelect = $('<select id="rum-ie-program"/>');
      programSelect.append($('<option/>',{value:'',text:'-'}));
      opts.programs.forEach(function(p){ programSelect.append($('<option/>',{value:p,text:p})); });
      programSelect.val(usr.program);

      const sitesInput = $('<input id="rum-ie-sites" type="text" placeholder="Comma-separated"/>').val((usr.sites||[]).join(', '));

      $row.find('td.column-role').empty().append(roleSelect);
      $row.find('td.column-parent').empty().append(parentSelect);
      $row.find('td.column-program').empty().append(programSelect);
      $row.find('td.column-site').empty().append(sitesInput);
      $row.find('td.column-actions').html('<a href="#" class="button button-primary rum-ie-save">Save</a> <a href="#" class="button rum-ie-cancel">Cancel</a>');

      function applyRoleRules(){
        const role = $('#rum-ie-role').val();
        if(role === 'program-leader' || role === 'data-viewer'){
          $('#rum-ie-parent').val('0').prop('disabled', true);
          $('#rum-ie-program').prop('disabled', role==='data-viewer');
          $('#rum-ie-sites').prop('disabled', false);
        } else if(role === 'site-supervisor'){
          $('#rum-ie-parent').prop('disabled', false);
          $('#rum-ie-program').prop('disabled', true);
          $('#rum-ie-sites').prop('disabled', false);
        } else if(role === 'frontline-staff'){
          $('#rum-ie-parent').prop('disabled', false);
          $('#rum-ie-program').prop('disabled', true);
          $('#rum-ie-sites').prop('disabled', true);
        } else {
          $('#rum-ie-parent, #rum-ie-program, #rum-ie-sites').prop('disabled', false);
        }
      }
      applyRoleRules();
      $row.on('change', '#rum-ie-role', applyRoleRules);

      $row.on('click', '.rum-ie-save', function(e){
        e.preventDefault();
        const payload = {
          action: 'rum_quick_update_user',
          user_id: userId,
          role: $('#rum-ie-role').val(),
          parent_user_id: $('#rum-ie-parent').val(),
          program: $('#rum-ie-program').val(),
          sites: $('#rum-ie-sites').val(),
          nonce
        };
        $.post(ajaxurl, payload).done(function(resp){
          if(resp && resp.success){
            // Simplest: reload to reflect changes
            location.reload();
          } else {
            alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Update failed');
          }
        }).fail(function(){ alert('Update error'); });
      });

      $row.on('click', '.rum-ie-cancel', function(e){ e.preventDefault(); restore(); });

      function restore(){
        const orig = $row.data('rum-original') || {};
        $row.find('td.column-role').html(orig.role||'');
        $row.find('td.column-parent').html(orig.parent||'');
        $row.find('td.column-program').html(orig.program||'');
        $row.find('td.column-site').html(orig.site||'');
        $row.find('td.column-actions').html(orig.actions||'');
        $row.data('rum-editing', false);
      }
    }).fail(function(){
      $row.data('rum-editing', false);
      alert('Error loading edit data');
    });
  }

  // Export CSV from Tab 1
  $(document).on('click', '#rum-export-csv', function(e){
    e.preventDefault();
    const nonce = $('#rum_export_nonce').val();
    const data = {
      action: 'arc_export_users',
      _wpnonce: nonce,
      filter_role: $('#filter_role').val() || '',
      filter_parent: $('#filter_parent').val() || '',
      filter_program: $('#filter_program').val() || '',
      filter_site: $('#filter_site').val() || '',
      filter_training_status: '',
      filter_date_start: '',
      filter_date_end: ''
    };
    $.post(ajaxurl, data).done(function(resp){
      if(resp && resp.success){
        const csvContent = resp.data.csv_content || '';
        const fn = resp.data.filename || 'export.csv';
        const blob = new Blob([csvContent], {type: 'text/csv;charset=utf-8;'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = fn; a.click();
        URL.revokeObjectURL(url);
      } else {
        alert('Export failed');
      }
    }).fail(function(){ alert('Export error'); });
  });

  // Data Viewer role → users
  function loadUsersByRole(){
    const role = $('#rum-viewer-role').val();
    const nonce = $('#rum_export_nonce').val() || (window.arc_dashboard_vars ? window.arc_dashboard_vars.nonce : '');
    if(!role) return;
    $.post(ajaxurl, { action: 'rum_viewer_get_users_by_role', role, nonce: nonce }).done(function(resp){
      const $sel = $('#rum-viewer-user');
      $sel.empty().append($('<option/>', {value: '', text: 'Select a user'}));
      if(resp && resp.success && resp.data.users){
        resp.data.users.forEach(function(u){
          $sel.append($('<option/>', {value: u.id, text: u.name}));
        });
      }
    });
  }
  $(document).on('change', '#rum-viewer-role', loadUsersByRole);
  if($('#rum-viewer-role').length){ loadUsersByRole(); }

  // Load hierarchy
  $(document).on('click', '#rum-load-hierarchy', function(){
    const userId = parseInt($('#rum-viewer-user').val() || '0', 10);
    const nonce = $('#rum_export_nonce').val() || (window.arc_dashboard_vars ? window.arc_dashboard_vars.nonce : '');
    if(!userId){ alert('Select a user'); return; }
    $('#rum-hierarchy-container').html('<em>Loading...</em>');
    $.post(ajaxurl, { action: 'rum_viewer_get_hierarchy', user_id: userId, nonce: nonce }).done(function(resp){
      if(resp && resp.success && resp.data.tree){
        const html = renderTree(resp.data.tree);
        $('#rum-hierarchy-container').html(html);
      } else {
        $('#rum-hierarchy-container').html('<em>No data</em>');
      }
    }).fail(function(){ $('#rum-hierarchy-container').html('<em>Error</em>'); });
  });

  function renderTree(node){
    if(!node) return '';
    function renderNode(n){
      let html = '<li><strong>' + escapeHtml(n.label || '') + '</strong>';
      if(n.children && n.children.length){
        html += '<ul>';
        n.children.forEach(function(child){ html += renderNode(child); });
        html += '</ul>';
      }
      html += '</li>';
      return html;
    }
    return '<ul class="rum-tree">' + renderNode(node) + '</ul>';
  }

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[m]); });
  }
});


