<article class="module width_full">
  <header><h3>General Statistics</h3></header>
  <div class="module_content">
    <table class="" width="50%" style="font-size:14px;">
      <tbody>
        <tr>
          <td class="leftheader">Pool Hash Rate</td>
          <td>{$GLOBAL.hashrate} {$GLOBAL.hashunits.pool}</td>
        </tr>
        <tr>
          <td class="leftheader">Current Total Miners</td>
          <td>{$GLOBAL.workers}</td>
        </tr>
        <tr>
          <td class="leftheader">Current Block</td>
          <td><a href="http://explorer.litecoin.net/search?q={$CURRENTBLOCK}" target="_new">{$CURRENTBLOCK}</a></td>
        </tr>
        <tr>
          <td class="leftheader">Current Difficulty</td>
          <td><a href="http://allchains.info/" target="_new">{$DIFFICULTY}</a></td>
        </tr>
      </tbody>
    </table>
  </div>
  <footer>
{if !$GLOBAL.website.api.disabled}<ul><li>These stats are also available in JSON format <a href="{$smarty.server.PHP_SELF}?page=api&action=public" target="_api">HERE</a></li>{/if}
  </footer>
</article>
