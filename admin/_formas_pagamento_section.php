<?php
/**
 * Seção: Meios de Pagamento com Conta Bancária
 * Incluído em pagamentos.php
 */
?>
<!-- ════════════════════════════════════════════════════════════
     SEÇÃO 3 — Meios de Pagamento (Formas de Pagamento)
     ════════════════════════════════════════════════════════════ -->
<div class="row" style="margin-top:24px;">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <h4 style="margin:0;">
                    <i class="fas fa-money-bill-wave"></i> Meios de Pagamento
                    <?php if (!empty($estab_selecionado_nome)): ?>
                        <small style="font-weight:normal;font-size:13px;color:var(--gray-300);">
                            — <?php echo htmlspecialchars($estab_selecionado_nome); ?>
                        </small>
                    <?php endif; ?>
                </h4>
                <button class="btn btn-success btn-sm" onclick="abrirModalForma(null)">
                    <i class="fas fa-plus"></i> Novo Meio de Pagamento
                </button>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (!$fp_has_conta || !$fp_has_metodos): ?>
                <div class="alert alert-warning" style="margin:16px;border-radius:6px;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Migração necessária:</strong> Execute o arquivo
                    <code>sql/migration_formas_pagamento_conta_bancaria.sql</code>
                    no phpMyAdmin para habilitar a vinculação de conta bancária e métodos aceitos.
                </div>
                <?php endif; ?>

                <?php if (empty($formas_pagamento_lista)): ?>
                <div style="padding:24px;text-align:center;color:var(--gray-500);">
                    <i class="fas fa-credit-card" style="font-size:32px;margin-bottom:8px;display:block;"></i>
                    Nenhum meio de pagamento cadastrado para este estabelecimento.
                    <br><small>Clique em "Novo Meio de Pagamento" para começar.</small>
                </div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="table" style="margin:0;">
                        <thead>
                            <tr>
                                <?php if ($fp_has_nome): ?><th>Nome</th><?php endif; ?>
                                <th>Tipo</th>
                                <th>Bandeira</th>
                                <th>Taxa %</th>
                                <th>Taxa Fixa</th>
                                <?php if ($fp_has_metodos): ?><th>Métodos Aceitos</th><?php endif; ?>
                                <?php if ($fp_has_conta): ?><th>Conta Bancária</th><?php endif; ?>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        // Montar mapa de contas bancárias para exibição
                        $cb_map = [];
                        foreach ($contas_bancarias_lista as $cb) {
                            $cb_map[$cb['id']] = $cb;
                        }
                        $tipo_labels = [
                            'pix'     => ['label' => 'PIX',     'color' => '#27ae60', 'icon' => 'fas fa-qrcode'],
                            'credito' => ['label' => 'Crédito', 'color' => '#2980b9', 'icon' => 'fas fa-credit-card'],
                            'debito'  => ['label' => 'Débito',  'color' => '#8e44ad', 'icon' => 'fas fa-credit-card'],
                            'dinheiro'=> ['label' => 'Dinheiro','color' => '#e67e22', 'icon' => 'fas fa-money-bill'],
                        ];
                        $metodo_labels = [
                            'pix'    => 'PIX',
                            'credit' => 'Crédito',
                            'debit'  => 'Débito',
                            'cash'   => 'Dinheiro',
                        ];
                        foreach ($formas_pagamento_lista as $fp):
                            $tl = $tipo_labels[$fp['tipo']] ?? ['label' => ucfirst($fp['tipo']), 'color' => '#555', 'icon' => 'fas fa-circle'];
                            $metodos_arr = !empty($fp['metodos_aceitos']) ? explode(',', $fp['metodos_aceitos']) : [];
                            $cb_vinc = $fp_has_conta && !empty($fp['conta_bancaria_id']) ? ($cb_map[$fp['conta_bancaria_id']] ?? null) : null;
                        ?>
                        <tr>
                            <?php if ($fp_has_nome): ?>
                            <td><strong><?php echo htmlspecialchars($fp['nome'] ?? '—'); ?></strong></td>
                            <?php endif; ?>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:5px;background:<?php echo $tl['color']; ?>22;color:<?php echo $tl['color']; ?>;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;">
                                    <i class="<?php echo $tl['icon']; ?>"></i> <?php echo $tl['label']; ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($fp['bandeira'] ?? '—'); ?></td>
                            <td><?php echo number_format((float)$fp['taxa_percentual'], 2, ',', '.'); ?>%</td>
                            <td>R$ <?php echo number_format((float)$fp['taxa_fixa'], 2, ',', '.'); ?></td>
                            <?php if ($fp_has_metodos): ?>
                            <td>
                                <?php if (empty($metodos_arr)): ?>
                                    <span style="color:var(--gray-500);font-size:12px;">—</span>
                                <?php else: ?>
                                    <?php foreach ($metodos_arr as $m): ?>
                                    <span style="display:inline-block;background:#e8f4fd;color:#2980b9;border-radius:8px;padding:2px 8px;font-size:11px;margin:1px;">
                                        <?php echo $metodo_labels[trim($m)] ?? trim($m); ?>
                                    </span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <?php if ($fp_has_conta): ?>
                            <td>
                                <?php if ($cb_vinc): ?>
                                    <span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;">
                                        <i class="fas fa-university" style="color:#3498db;"></i>
                                        <strong><?php echo htmlspecialchars($cb_vinc['nome']); ?></strong>
                                        <?php if (!empty($cb_vinc['banco'])): ?>
                                            <small style="color:var(--gray-500);">(<?php echo htmlspecialchars($cb_vinc['banco']); ?>)</small>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#e67e22;font-size:12px;"><i class="fas fa-exclamation-circle"></i> Não vinculada</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td>
                                <?php if ($fp['ativo']): ?>
                                    <span class="badge badge-success">Ativo</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Inativo</span>
                                <?php endif; ?>
                            </td>
                            <td class="action-buttons">
                                <button class="btn btn-sm btn-primary"
                                    onclick='abrirModalForma(<?php echo json_encode([
                                        "id"               => $fp["id"],
                                        "nome"             => $fp["nome"] ?? "",
                                        "tipo"             => $fp["tipo"],
                                        "bandeira"         => $fp["bandeira"] ?? "",
                                        "taxa_percentual"  => $fp["taxa_percentual"],
                                        "taxa_fixa"        => $fp["taxa_fixa"],
                                        "conta_bancaria_id"=> $fp["conta_bancaria_id"] ?? "",
                                        "metodos_aceitos"  => $fp["metodos_aceitos"] ?? "",
                                        "ativo"            => $fp["ativo"],
                                    ]); ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-danger"
                                    onclick="confirmarExcluirForma(<?php echo $fp['id']; ?>, <?php echo $fp['estabelecimento_id']; ?>, '<?php echo addslashes($fp['nome'] ?? $fp['tipo']); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     MODAL — Novo / Editar Meio de Pagamento
     ════════════════════════════════════════════════════════════ -->
<div class="modal" id="modalForma" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1050;align-items:center;justify-content:center;">
    <div class="modal-content" style="max-width:560px;width:95%;background:#fff;border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,.2);overflow:hidden;">
        <div class="modal-header" style="background:var(--primary);color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;">
            <h3 id="modalFormaTitle" style="margin:0;font-size:16px;"><i class="fas fa-money-bill-wave"></i> Novo Meio de Pagamento</h3>
            <button onclick="fecharModalForma()" style="background:none;border:none;color:#fff;font-size:20px;cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body" style="padding:20px;">
            <form method="POST">
                <input type="hidden" name="save_forma_pagamento" value="1">
                <input type="hidden" name="fp_id" id="fp_id">
                <input type="hidden" name="fp_estabelecimento_id" value="<?php echo $estab_selecionado_id; ?>">

                <?php if ($fp_has_nome): ?>
                <div class="form-group">
                    <label>Nome / Apelido</label>
                    <input type="text" name="fp_nome" id="fp_nome" class="form-control" placeholder="Ex: PIX Principal, Cartão Bradesco">
                </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Tipo *</label>
                            <select name="fp_tipo" id="fp_tipo" class="form-control" required>
                                <option value="pix">PIX</option>
                                <option value="credito">Crédito</option>
                                <option value="debito">Débito</option>
                                <option value="dinheiro">Dinheiro</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Bandeira</label>
                            <input type="text" name="fp_bandeira" id="fp_bandeira" class="form-control" placeholder="Visa, Mastercard, Elo...">
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Taxa % <small style="color:var(--gray-500);">(ex: 2.50)</small></label>
                            <input type="number" name="fp_taxa_percentual" id="fp_taxa_percentual" class="form-control" step="0.01" min="0" value="0">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label>Taxa Fixa R$</label>
                            <input type="number" name="fp_taxa_fixa" id="fp_taxa_fixa" class="form-control" step="0.01" min="0" value="0">
                        </div>
                    </div>
                </div>

                <?php if ($fp_has_metodos): ?>
                <div class="form-group">
                    <label>Métodos de Pagamento Aceitos</label>
                    <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:6px;">
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:normal;">
                            <input type="checkbox" name="fp_metodos[]" id="fp_metodo_pix" value="pix"> PIX
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:normal;">
                            <input type="checkbox" name="fp_metodos[]" id="fp_metodo_credit" value="credit"> Crédito
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:normal;">
                            <input type="checkbox" name="fp_metodos[]" id="fp_metodo_debit" value="debit"> Débito
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:normal;">
                            <input type="checkbox" name="fp_metodos[]" id="fp_metodo_cash" value="cash"> Dinheiro
                        </label>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($fp_has_conta): ?>
                <div class="form-group">
                    <label><i class="fas fa-university"></i> Conta Bancária Vinculada</label>
                    <?php if (empty($contas_bancarias_lista)): ?>
                    <div class="alert alert-warning" style="font-size:13px;padding:8px 12px;">
                        <i class="fas fa-exclamation-triangle"></i>
                        Nenhuma conta bancária cadastrada.
                        <a href="financeiro_contas_bancarias.php" target="_blank">Cadastrar conta</a>
                    </div>
                    <?php else: ?>
                    <select name="fp_conta_bancaria_id" id="fp_conta_bancaria_id" class="form-control">
                        <option value="">— Nenhuma conta vinculada —</option>
                        <?php foreach ($contas_bancarias_lista as $cb): ?>
                        <option value="<?php echo $cb['id']; ?>">
                            <?php echo htmlspecialchars($cb['nome']); ?>
                            <?php if (!empty($cb['banco'])): ?>(<?php echo htmlspecialchars($cb['banco']); ?>)<?php endif; ?>
                            — Saldo: R$ <?php echo number_format((float)$cb['saldo_atual'], 2, ',', '.'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:var(--gray-500);">Os valores pagos por este meio serão lançados nesta conta automaticamente.</small>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <input type="hidden" name="fp_conta_bancaria_id" id="fp_conta_bancaria_id" value="">
                <?php endif; ?>

                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal;">
                        <input type="checkbox" name="fp_ativo" id="fp_ativo" value="1" checked>
                        Ativo
                    </label>
                </div>

                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
                    <button type="button" class="btn btn-secondary" onclick="fecharModalForma()">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>
