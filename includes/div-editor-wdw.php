<div id="editorWdw" class="clsModalWdw" style="z-index:100; max-height: 100%">
  <div id="black_overlay"></div>
  <div id="editorForm">
    <div id="editorFormHeader">
      <a id="cancelEdit" class="form-close-x" href="#" onclick="editWdwClose()"><i class="material-icons md-18 greyred">close</i></a>
      <div id="editorFormHeaderMenu">
        <div id="menuForm">
          <a id="discardEdit" class="form-button red-form-button" href="#" onclick="editWdwClose()">Discard</a>
          <a id="saveEdit" class="form-button green-form-button" href="#">Save</a>
        </div>
      </div>
      <div id="editorFormHeaderText"></div>
    </div>
    <div id="editorFormMain">
      <div id="dataPicture" style="display:none">
        <img id="dataPicPrev" src="#" height="100%" width="100%" />
        <input id="imgfile" style="width:125px; height: 30px; line-hight: 30px; margin-top: 3px" type="file" onchange="readPhotoURL(this)" class="custom-input" title="" accept="image/*" />
      </div>
    </div>
    <div id="editorFormTab" style="display:none">
      <ul class="tabs">
        <li>
          <input type="radio" name="tabs" id="tab1" checked/>
          <label for="tab1" id="labelTab1"></label>
          <div id="tab-content1" class="tab-content tab-content-1"></div>
        </li>
        <li>
          <input type="radio" name="tabs" id="tab2"/>
          <label for="tab2" id="labelTab2"></label>
          <div id="tab-content2" class="tab-content"></div>
        </li>
        <li id="formTab3" style="display:none">
          <input type="radio" name="tabs" id="tab3"/>
          <label for="tab3" id="labelTab3"></label>
          <div id="tab-content3" class="tab-content"></div>
        </li>
      </ul>
    </div>
    <div id="editorFormTabMenu" style="display:none">
      <div id="menuForm">
        <a id="addDataTab" class="form-button form-button-editor green-form-button" href="#">Add</a>
        <a id="editDataTab" class="form-button form-button-editor red-form-button" style="display:none" href="#">Edit</a>
        <a id="deleteDataTab" class="form-button form-button-editor red-form-button" style="display:none" href="#">Delete</a>
      </div>
      <div id="menuFormEditor">
        <a id="formEditorTabSave" class="form-button form-button-editor green-form-button" style="display:none" href="#">Update</a>
        <a id="formEditorTabCancel" class="form-button form-button-editor red-form-button" style="display:none" href="#">Cancel</a>
      </div>
    </div>
    <div id="editorFormGrid"></div>
  </div>
</div>
