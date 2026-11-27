<div id="addWdw" class="clsModalWdw" style="z-index:200; display:none">
  <div id="black_overlay"></div>
  <div id="addForm">
    <div id="addFormHeader">
      <!-- Ubah href="#" menjadi href="javascript:void(0);" -->
      <a id="cancelEdit" class="form-close-x" href="javascript:void(0);" onclick="addWdwClose()"><i class="material-icons md-18 greyred">close</i></a>
      <div id="addFormHeaderMenu">
        <div id="menuForm">
          <!-- Ganti tag <a> menjadi <button type="button"> agar 100% bebas dari reload -->
          <button type="button" id="addDiscardEdit" class="form-button red-form-button" onclick="addWdwClose()">Discard</button>
          <button type="button" id="addSaveEdit" class="form-button green-form-button">OK</button>
        </div>
      </div>
      <div id="addFormHeaderSearch" style="display:none">
        <input id="qAdd" name="qAdd" type="text" onkeydown="replaceEnterKeyWithTab()" size="40" placeholder="Search..."/>
      </div>
      <div id="addFormHeaderText"></div>
    </div>
    <div id="addFormMain"></div>
    <div id="addFormGrid"></div>
  </div>
</div>