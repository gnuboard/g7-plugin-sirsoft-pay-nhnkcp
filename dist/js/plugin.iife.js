(function(){"use strict";async function w(n,s){const{pgPaymentData:e}=n.params||{};if(!e){console.error("[sirsoft-pay-nhnkcp] pgPaymentData is required");return}const r=window.G7Core;try{const t=await r.api.get("/modules/sirsoft-ecommerce/payments/client-config/nhnkcp");if(!t.data){console.error("[sirsoft-pay-nhnkcp] Failed to fetch client config",t);return}const o=t.data,l=window.location.origin+o.callback_urls.callback,k={card:"100000000000",bank:"010000000000",vbank:"001000000000",phone:"000100000000"}[e.pay_method??"card"]??"100000000000",b={site_cd:o.client_id,ordr_idxx:e.order_number,good_name:e.order_name,good_mny:String(e.amount),buyr_name:e.customer_name??"",buyr_mail:e.customer_email??"",buyr_tel1:e.customer_phone??"",pay_method:k,Ret_URL:l},P=Object.entries(b).map(([u,m])=>`<input type="hidden" name="${u}" value="${m.replace(/"/g,"&quot;")}">`).join("");await new Promise((u,m)=>{const h=document.getElementById("kcp-sdk-iframe");h&&h.remove();const i=document.createElement("iframe");i.id="kcp-sdk-iframe",i.style.cssText="position:fixed;top:0;left:0;width:100%;height:100%;border:0;z-index:99999;background:#fff;",document.body.appendChild(i);const d=i.contentWindow;d.m_Completepayment=function(a){a.action=l,a.method="POST",a.target="_top",d.document.body.appendChild(a),a.submit()},d.__kcpDone=u,d.__kcpFail=a=>{i.remove(),m(a)};const f=i.contentDocument||d.document;f.open(),f.write(`<!DOCTYPE html><html><head>
<script src="${o.sdk_url}"><\/script>
</head><body style="margin:0;padding:0;">
<form name="order_info">${P}</form>
<script>
try {
  if (typeof KCP_Pay_Execute === 'function') {
    KCP_Pay_Execute(document.forms['order_info']);
    window.__kcpDone && window.__kcpDone();
  } else {
    window.__kcpFail && window.__kcpFail(new Error('KCP_Pay_Execute not defined'));
  }
} catch(e) {
  window.__kcpFail && window.__kcpFail(e);
}
<\/script>
</body></html>`),f.close(),setTimeout(()=>{i.remove(),m(new Error("KCP SDK load timeout"))},15e3)})}catch(t){console.error("[sirsoft-pay-nhnkcp] requestPayment error",t);const o=t instanceof Error?t.message:"Unknown error";r?.state?.setLocal?.({paymentErrorMessage:o,isSubmittingOrder:!1}),r?.modal?.open?.("nhnkcp_payment_error_modal")}}const _={requestPayment:w},c="sirsoft-pay-nhnkcp",p={info:(...n)=>console.info(`[${c}]`,...n),warn:(...n)=>console.warn(`[${c}]`,...n),error:(...n)=>console.error(`[${c}]`,...n)};function y(){const n=window.G7Core;if(!n)return 0;const s=n.getActionDispatcher;if(typeof s!="function")return 0;const e=s();if(!e||typeof e.registerHandler!="function")return 0;let r=0;for(const[t,o]of Object.entries(_)){const l=`${c}.${t}`;e.registerHandler(l,o,{category:"plugin",source:c}),r++}return r}function g(){const n=()=>{const s=y();if(s>0){p.info(`${s} handler(s) registered`);return}let e=0;const r=50,t=setInterval(()=>{e++;const o=y();if(o>0){clearInterval(t),p.info(`${o} handler(s) registered (after ${e} retries)`);return}e>=r&&(clearInterval(t),p.warn("ActionDispatcher not available after timeout"))},100)};document.readyState==="loading"?document.addEventListener("DOMContentLoaded",n):n()}g(),window.__SirsoftNhnkcp={identifier:c,handlers:Object.keys(_),initPlugin:g}})();
//# sourceMappingURL=plugin.iife.js.map
